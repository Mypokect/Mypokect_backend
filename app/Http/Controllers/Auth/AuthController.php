<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponse;

    private const PASSWORD_RESET_CODE_TTL_SECONDS = 30;
    private const PASSWORD_RESET_TOKEN_TTL_MINUTES = 10;

    /**
     * Login.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'phone'    => 'required|string|max:20',
            'password' => 'required|string',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors(), 'Datos de inicio de sesión inválidos');
        }

        $throttleKey = 'login_attempt:' . Str::lower($request->input('phone'));

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return $this->errorResponse("Cuenta bloqueada temporalmente. Intente en {$seconds} segundos.", 429);
        }

        try {
            $user = User::where('phone', $request->input('phone'))->first();

            if (! $user || ! Hash::check($request->input('password'), $user->password)) {
                RateLimiter::hit($throttleKey, 600);
                return $this->errorResponse('Credenciales inválidas', 401);
            }

            RateLimiter::clear($throttleKey);
            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->successResponse(['user' => $user, 'token' => $token], 'Inicio de sesión exitoso');
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Register.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'name'         => 'required|string|max:255',
            'phone'        => 'required|string|max:20|unique:users,phone',
            'country_code' => 'required|string|max:5',
            'password'     => 'required|digits:4',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors(), 'Datos de registro inválidos');
        }

        try {
            $user = User::create([
                'name'         => $request->input('name'),
                'phone'        => $request->input('phone'),
                'country_code' => $request->input('country_code'),
                'password'     => Hash::make($request->input('password')),
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->createdResponse(['user' => $user, 'token' => $token], 'Usuario registrado exitosamente');
        } catch (\Exception $e) {
            Log::error('Register error: ' . $e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Request a password recovery code by phone.
     */
    public function requestPasswordResetCode(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors(), 'Datos inválidos para recuperar la contraseña');
        }

        $phone = trim((string) $request->input('phone'));
        $user = User::where('phone', $phone)->first();

        if (! $user) {
            return $this->errorResponse('No existe una cuenta asociada a ese número de teléfono.', 404);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->passwordResetCodeCacheKey($phone), [
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addSeconds(self::PASSWORD_RESET_CODE_TTL_SECONDS)->toIso8601String(),
        ], now()->addSeconds(self::PASSWORD_RESET_CODE_TTL_SECONDS));

        // TODO: Reemplazar este log por el envío real mediante un proveedor SMS.
        Log::info('Password recovery code generated', [
            'user_id' => $user->id,
            'phone' => $phone,
            'code' => $code,
            'expires_in' => self::PASSWORD_RESET_CODE_TTL_SECONDS,
        ]);

        $data = [
            'phone' => $phone,
            'expires_in' => self::PASSWORD_RESET_CODE_TTL_SECONDS,
        ];

        if (! app()->isProduction()) {
            $data['debug_code'] = $code;
        }

        return $this->successResponse($data, 'Código enviado correctamente.');
    }

    /**
     * Verify recovery SMS code.
     */
    public function verifyPasswordResetCode(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'code' => 'required|digits:6',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors(), 'Datos inválidos para verificar el código');
        }

        $phone = trim((string) $request->input('phone'));
        $payload = Cache::get($this->passwordResetCodeCacheKey($phone));

        if (! is_array($payload) || ! isset($payload['code'], $payload['user_id'])) {
            return $this->errorResponse('El código expiró. Solicita uno nuevo.', 422);
        }

        if ((string) $payload['code'] !== (string) $request->input('code')) {
            return $this->errorResponse('El código ingresado no es válido.', 422);
        }

        $resetToken = (string) Str::uuid();
        Cache::put($this->passwordResetTokenCacheKey($phone, $resetToken), [
            'user_id' => $payload['user_id'],
        ], now()->addMinutes(self::PASSWORD_RESET_TOKEN_TTL_MINUTES));

        Cache::forget($this->passwordResetCodeCacheKey($phone));

        return $this->successResponse([
            'phone' => $phone,
            'reset_token' => $resetToken,
            'reset_token_expires_in' => self::PASSWORD_RESET_TOKEN_TTL_MINUTES * 60,
        ], 'Código verificado correctamente.');
    }

    /**
     * Reset password using a verified recovery token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'reset_token' => 'required|string',
            'password' => 'required|digits:4|confirmed',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors(), 'Datos inválidos para actualizar la contraseña');
        }

        $phone = trim((string) $request->input('phone'));
        $resetToken = (string) $request->input('reset_token');
        $tokenPayload = Cache::get($this->passwordResetTokenCacheKey($phone, $resetToken));

        if (! is_array($tokenPayload) || ! isset($tokenPayload['user_id'])) {
            return $this->errorResponse('La validación expiró. Solicita un nuevo código.', 422);
        }

        $user = User::where('id', $tokenPayload['user_id'])
            ->where('phone', $phone)
            ->first();

        if (! $user) {
            return $this->errorResponse('No existe una cuenta asociada a ese número de teléfono.', 404);
        }

        $user->password = Hash::make((string) $request->input('password'));
        $user->save();

        Cache::forget($this->passwordResetTokenCacheKey($phone, $resetToken));
        Cache::forget($this->passwordResetCodeCacheKey($phone));

        return $this->successResponse(null, 'Contraseña actualizada correctamente.');
    }

    private function passwordResetCodeCacheKey(string $phone): string
    {
        return 'password_reset_code:' . Str::lower($phone);
    }

    private function passwordResetTokenCacheKey(string $phone, string $token): string
    {
        return 'password_reset_token:' . Str::lower($phone) . ':' . $token;
    }

    /**
     * Get home data.
     *
     * Accepts optional ?month=&year= query params (defaults to current month).
     * Returns balance, status semáforo, porcentaje_ahorro, salud_financiera y flujo_neto.
     */
    public function homeData(Request $request): JsonResponse
    {
        $user  = $request->user();
        $month = (int) $request->query('month', now()->month);
        $year  = (int) $request->query('year',  now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = Carbon::create($year, $month, 1)->endOfMonth();

        $incomes  = (float) $user->movements()->where('type', 'income') ->whereBetween('created_at', [$start, $end])->sum('amount');
        $expenses = (float) $user->movements()->where('type', 'expense')->whereBetween('created_at', [$start, $end])->sum('amount');

        $balance   = $incomes - $expenses;
        $flujoNeto = $balance;

        // ── Semáforo ──────────────────────────────────────────────────────────
        $statusLabel = 'Sin actividad';
        $statusColor = 'grey';
        $iconType    = 'neutral';

        if ($incomes > 0 || $expenses > 0) {
            if ($balance >= 0) {
                $statusLabel = 'Saldo a favor';
                $statusColor = 'green';
                $iconType    = 'up';
            } else {
                $statusLabel = 'Sobregirado';
                $statusColor = 'red';
                $iconType    = 'down';
            }
        }

        // ── Porcentaje de ahorro (backend calcula, Flutter solo muestra) ──────
        $porcentajeAhorro = 0.0;
        if ($incomes > 0) {
            $porcentajeAhorro = max(0.0, round(($balance / $incomes) * 100, 1));
        }

        // ── Reto de Ahorro Activo + Alcancía con Fuga ────────────────────────
        $hasActiveChallenge    = false;
        $ahorroReservadoMes    = 0.0;
        $ahorroProtegidoActual = 0.0;
        $dineroFugadoMes       = 0.0;
        $disponibleParaVivir   = $balance;

        if (Schema::hasColumn('users', 'has_active_challenge')) {
            $hasActiveChallenge = (bool) $user->has_active_challenge;

            if ($hasActiveChallenge && $user->savings_mode_pct > 0) {
                $ahorroReservadoMes = round($incomes * (float) $user->savings_mode_pct, 2);

                // challenge_savings_balance = monto real protegido tras fugas
                $ahorroProtegidoActual = Schema::hasColumn('users', 'challenge_savings_balance')
                    ? (float) ($user->challenge_savings_balance ?? $ahorroReservadoMes)
                    : $ahorroReservadoMes;

                $dineroFugadoMes   = max(0.0, round($ahorroReservadoMes - $ahorroProtegidoActual, 2));
                $disponibleParaVivir = round($balance - $ahorroProtegidoActual, 2);
            }
        }

        // ── Salud Financiera (score 0-100 + etiqueta + color) ─────────────────
        $saludFinanciera = $this->calcularSaludFinanciera($incomes, $expenses);

        return $this->successResponse([
            'name'                 => $user->name,
            'balance'              => $balance,
            'flujo_neto'           => $flujoNeto,
            'status_label'         => $statusLabel,
            'status_color'         => $statusColor,
            'icon_type'            => $iconType,
            'porcentaje_ahorro'    => $porcentajeAhorro,
            'salud_financiera'     => $saludFinanciera,
            // Reto de Ahorro + Alcancía con Fuga — Flutter mapea directamente
            'has_active_challenge'    => $hasActiveChallenge,
            'ahorro_reservado_mes'    => $ahorroReservadoMes,
            'ahorro_protegido_actual' => $ahorroProtegidoActual,
            'dinero_fugado_mes'       => $dineroFugadoMes,
            'disponible_para_vivir'   => $disponibleParaVivir,
        ]);
    }

    /**
     * Get financial summary (with month/year filter).
     */
    public function financialSummary(Request $request): JsonResponse
    {
        $user  = $request->user();
        $month = (int) $request->query('month', now()->month);
        $year  = (int) $request->query('year',  now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = Carbon::create($year, $month, 1)->endOfMonth();

        $totalIncome = (float) $user->movements()
            ->where('type', 'income')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $totalExpense = (float) $user->movements()
            ->where('type', 'expense')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $totalGoalContributions = (float) $user->goalContributions()
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $topTags = $user->movements()
            ->leftJoin('tags', 'movements.tag_id', '=', 'tags.id')
            ->whereBetween('movements.created_at', [$start, $end])
            ->whereNotNull('movements.tag_id')
            ->whereNotNull('tags.name')
            ->selectRaw('tags.name as tag_name, SUM(movements.amount) as total_amount')
            ->groupBy('tags.id', 'tags.name')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->mapWithKeys(function ($movement) {
                $tagName = trim((string) ($movement->tag_name ?? ''));
                return $tagName === '' ? [] : [$tagName => (float) $movement->total_amount];
            });

        return $this->successResponse([
            'total_income'             => $totalIncome,
            'total_expense'            => $totalExpense,
            'total_goal_contributions' => $totalGoalContributions,
            'top_tags'                 => $topTags,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function calcularSaludFinanciera(float $incomes, float $expenses): array
    {
        if ($incomes === 0.0) {
            return ['score' => 0, 'label' => 'Sin actividad', 'descripcion' => 'Registra ingresos para ver tu salud', 'color' => 'grey'];
        }

        $ratio = $expenses / $incomes;

        if ($ratio > 1.0) {
            return ['score' => 10, 'label' => 'Crítico',    'descripcion' => 'Ajusta tus finanzas urgentemente', 'color' => 'red'];
        }
        if ($ratio < 0.3) {
            return ['score' => 95, 'label' => 'Excelente',  'descripcion' => 'Tu salud financiera es óptima',    'color' => 'green'];
        }
        if ($ratio < 0.5) {
            return ['score' => 85, 'label' => 'Muy Bueno',  'descripcion' => 'Estás ahorrando muy bien',         'color' => 'green'];
        }
        if ($ratio < 0.7) {
            return ['score' => 70, 'label' => 'Bueno',      'descripcion' => 'Puedes mejorar tu ahorro',         'color' => 'orange'];
        }

        $score = (int) max(20, round((1 - $ratio) * 100));
        return ['score' => $score, 'label' => 'Regular', 'descripcion' => 'Necesitas optimizar gastos', 'color' => 'orange'];
    }
}
