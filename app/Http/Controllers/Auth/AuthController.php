<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\SchemaCache;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use App\Services\Billing\SubscriptionManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponse;

    private const PASSWORD_RESET_CODE_TTL_SECONDS = 30;

    private const PASSWORD_RESET_MAX_ATTEMPTS = 5;

    private const PASSWORD_RESET_TOKEN_TTL_MINUTES = 10;

    /**
     * Login.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'password' => 'required|string',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors(), 'Datos de inicio de sesión inválidos');
        }

        $throttleKey = 'login_attempt:'.Str::lower($request->input('phone'));

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
            Log::error('Login error: '.$e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Register.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:users,phone',
            'country_code' => 'required|string|max:5',
            'password' => 'required|digits:4',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors(), 'Datos de registro inválidos');
        }

        try {
            $user = User::create([
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
                'country_code' => $request->input('country_code'),
                'password' => Hash::make($request->input('password')),
            ]);

            // Prueba gratuita (14 días) para que el usuario use la app desde el
            // primer momento. No debe romper el registro si algo falla.
            try {
                app(SubscriptionManager::class)->startTrial($user);
            } catch (\Throwable $e) {
                Log::warning('No se pudo iniciar la prueba al registrar', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->createdResponse(['user' => $user, 'token' => $token], 'Usuario registrado exitosamente');
        } catch (\Exception $e) {
            Log::error('Register error: '.$e->getMessage());

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
        // El código NUNCA se escribe en el log: los logs suelen terminar en
        // agregadores externos y un código de recuperación es una credencial.
        Log::info('Password recovery code generated', [
            'user_id' => $user->id,
            'phone' => $phone,
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

        // hash_equals evita fugas por tiempo de comparación; el contador de
        // intentos invalida el código ante fuerza bruta (el throttle por IP
        // no protege contra ataques distribuidos).
        if (! hash_equals((string) $payload['code'], (string) $request->input('code'))) {
            $payload['attempts'] = (int) ($payload['attempts'] ?? 0) + 1;
            $expiresAt = isset($payload['expires_at'])
                ? Carbon::parse($payload['expires_at'])
                : now()->addSeconds(self::PASSWORD_RESET_CODE_TTL_SECONDS);

            if ($payload['attempts'] >= self::PASSWORD_RESET_MAX_ATTEMPTS || $expiresAt->isPast()) {
                Cache::forget($this->passwordResetCodeCacheKey($phone));

                return $this->errorResponse('Demasiados intentos fallidos. Solicita un código nuevo.', 429);
            }

            Cache::put($this->passwordResetCodeCacheKey($phone), $payload, $expiresAt);

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
        return 'password_reset_code:'.Str::lower($phone);
    }

    private function passwordResetTokenCacheKey(string $phone, string $token): string
    {
        return 'password_reset_token:'.Str::lower($phone).':'.$token;
    }

    /**
     * Get home data.
     *
     * Accepts optional ?month=&year= query params (defaults to current month).
     * Returns balance_total, disponible_para_vivir, flujo_neto, ahorro_mes_pct y salud_financiera.
     */
    public function homeData(Request $request): JsonResponse
    {
        $user = $request->user();
        $month = (int) $request->query('month', now('America/Bogota')->month);
        $year = (int) $request->query('year', now('America/Bogota')->year);

        // 2-minute cache per user+month+year.
        // Invalidated by MovementController::invalidateUserSummaryCache() on every mutation.
        $cacheKey = "home_data:{$user->id}:{$year}:{$month}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        // Build date range in Colombia timezone, then convert to UTC for DB queries
        $tz = 'America/Bogota';
        $start = Carbon::createFromDate($year, $month, 1, $tz)->startOfMonth()->utc();
        $end = Carbon::createFromDate($year, $month, 1, $tz)->endOfMonth()->utc();

        // One consolidated query replaces 4 separate aggregate queries.
        // Uses the compound index (user_id, type, created_at).
        $aggRow = DB::selectOne(
            'SELECT
                COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0)            AS total_income,
                COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0)            AS total_expense,
                COUNT(CASE WHEN type = ? THEN 1 END)                                   AS expense_count,
                COUNT(CASE WHEN type = ? AND has_invoice = 1 THEN 1 END)               AS invoice_count
             FROM movements
             WHERE user_id = ? AND created_at BETWEEN ? AND ?',
            ['income', 'expense', 'expense', 'expense', $user->id, $start, $end]
        );

        $incomes = (float) ($aggRow->total_income ?? 0.0);
        $expenses = (float) ($aggRow->total_expense ?? 0.0);

        $balance = $incomes - $expenses;
        $flujoNeto = $balance;

        // All-time balance: sums every movement the user ever recorded
        $allTimeRow = DB::selectOne(
            'SELECT
                COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) AS total_expense
             FROM movements WHERE user_id = ?',
            ['income', 'expense', $user->id]
        );
        $saldoHistorico = (float) ($allTimeRow->total_income ?? 0) - (float) ($allTimeRow->total_expense ?? 0);

        // ── Semáforo ──────────────────────────────────────────────────────────
        $statusLabel = 'Sin actividad';
        $statusColor = 'grey';
        $iconType = 'neutral';

        if ($incomes > 0 || $expenses > 0) {
            if ($balance >= 0) {
                $statusLabel = 'Saldo a favor';
                $statusColor = 'green';
                $iconType = 'up';
            } else {
                $statusLabel = 'Sobregirado';
                $statusColor = 'red';
                $iconType = 'down';
            }
        }

        // ── Porcentaje de ahorro (backend calcula, Flutter solo muestra) ──────
        $porcentajeAhorro = 0.0;
        if ($incomes > 0) {
            $porcentajeAhorro = max(0.0, round(($balance / $incomes) * 100, 1));
        }

        // ── Reto de Ahorro Activo + Alcancía con Fuga ────────────────────────
        $hasActiveChallenge = false;
        $ahorroReservadoMes = 0.0;
        $ahorroProtegidoActual = 0.0;
        $dineroFugadoMes = 0.0;
        $disponibleParaVivir = $balance;

        if (SchemaCache::hasColumn('users', 'has_active_challenge')) {
            $hasActiveChallenge = (bool) $user->has_active_challenge;

            if ($hasActiveChallenge && $user->savings_mode_pct > 0) {
                $ahorroReservadoMes = round($incomes * (float) $user->savings_mode_pct, 2);

                // challenge_savings_balance = monto real protegido tras fugas
                $ahorroProtegidoActual = SchemaCache::hasColumn('users', 'challenge_savings_balance')
                    ? (float) ($user->challenge_savings_balance ?? $ahorroReservadoMes)
                    : $ahorroReservadoMes;

                $dineroFugadoMes = max(0.0, round($ahorroReservadoMes - $ahorroProtegidoActual, 2));
                $disponibleParaVivir = round($balance - $ahorroProtegidoActual, 2);
            }
        }

        // ── Salud Financiera (score 0-100 + etiqueta + color) ─────────────────
        $saludFinanciera = $this->calcularSaludFinanciera($incomes, $expenses);

        // Texto corto para mostrar en UI sin lógica en Flutter
        $saludTexto = match ($saludFinanciera['label']) {
            'Excelente', 'Muy Bueno' => 'Bolsillo sano',
            'Bueno' => 'Puedes mejorar',
            'Regular' => 'Gasto elevado',
            'Crítico' => 'Finanzas en riesgo',
            default => 'Sin movimientos',
        };

        // ── Eficiencia Fiscal (valores ya calculados en la query consolidada) ────
        $totalExpenseCount = (int) ($aggRow->expense_count ?? 0);
        $expensesWithInvoice = (int) ($aggRow->invoice_count ?? 0);
        $eficienciaFiscal = $totalExpenseCount > 0 ? round(($expensesWithInvoice / $totalExpenseCount) * 100, 1) : 0.0;

        // ── Categoría de mayor gasto (actual vs mes anterior) ─────────────────
        $prevStart = $start->copy()->subMonth()->startOfMonth();
        $prevEnd = $start->copy()->subMonth()->endOfMonth();

        $topTag = $user->movements()
            ->leftJoin('tags', 'movements.tag_id', '=', 'tags.id')
            ->where('movements.type', 'expense')
            ->whereBetween('movements.created_at', [$start, $end])
            ->whereNotNull('movements.tag_id')
            ->whereNotNull('tags.name')
            ->selectRaw('tags.id as tag_id, tags.name as tag_name, SUM(movements.amount) as total_amount')
            ->groupBy('tags.id', 'tags.name')
            ->orderByDesc('total_amount')
            ->first();

        $alertaGasto = null;
        if ($topTag) {
            $tagName = (string) ($topTag->tag_name ?? '');
            $currentTotal = (float) ($topTag->total_amount ?? 0.0);
            $prevTotal = (float) ($user->movements()
                ->where('type', 'expense')
                ->whereBetween('created_at', [$prevStart, $prevEnd])
                ->where('tag_id', $topTag->tag_id)
                ->sum('amount') ?? 0.0);
            $alertaGasto = [
                'categoria' => $tagName,
                'monto' => round($currentTotal, 2),
                'excede_anterior' => $prevTotal > 0 && $currentTotal > $prevTotal,
                'variacion_pct' => $prevTotal > 0 ? round((($currentTotal - $prevTotal) / $prevTotal) * 100, 1) : null,
            ];
        }

        // ── Observaciones inteligentes ────────────────────────────────────────
        $observaciones = $this->generarObservaciones(
            $incomes, $expenses, $balance, $porcentajeAhorro,
            $eficienciaFiscal, $alertaGasto, $hasActiveChallenge, $ahorroProtegidoActual
        );

        // ── Objeto analisis_usuario (Flutter solo mapea, no calcula) ──────────
        $analisisUsuario = [
            'health_score' => $saludFinanciera['score'],
            'mensaje_diagnostico' => $saludFinanciera['descripcion'],
            'eficiencia_fiscal' => $eficienciaFiscal,
            'alerta_gasto' => $alertaGasto,
            'observaciones' => $observaciones,
        ];

        // ── Objeto analisis (alias conciso para KPI cards en Flutter) ─────────
        $nivelAhorro = match (true) {
            $porcentajeAhorro >= 30 => 'Bestia',
            $porcentajeAhorro >= 10 => 'Moderado',
            default => 'Bajo',
        };
        $consejoIa = match (true) {
            $balance < 0 => 'Recorta gastos no esenciales esta semana.',
            $porcentajeAhorro >= 30 => '¡Gran ritmo! Considera invertir tu excedente.',
            $eficienciaFiscal < 40 => 'Pide factura en tus compras y deduce más.',
            $hasActiveChallenge => 'Tu reto de ahorro está activo. ¡No lo abandones!',
            default => 'Mantén el registro de tus gastos diarios.',
        };
        $analisis = [
            'score' => $saludFinanciera['score'],
            'nivel_ahorro' => $nivelAhorro,
            'facturacion_pct' => $eficienciaFiscal,
            'consejo_ia' => $consejoIa,
        ];

        // ── Distribución de Saldo por Cuenta ─────────────────────────────────
        $distribucionSaldo = [];
        try {
            if (SchemaCache::hasTable('accounts')) {
                $distribucionSaldo = $user->accounts()
                    ->select('entidad', 'monto', 'tipo')
                    ->get()
                    ->map(fn ($a) => [
                        'entidad' => $a->entidad,
                        'monto' => (float) $a->monto,
                        'tipo' => $a->tipo,
                    ])
                    ->values()
                    ->toArray();
            }
        } catch (\Throwable) {
            // Tabla aún no migrada — devuelve lista vacía sin romper la respuesta
        }

        $responsePayload = [
            'status' => 'success',
            'data' => [
                'name' => $user->name ?? '',
                'balance_total' => (float) $saldoHistorico,
                'flujo_neto' => (float) $flujoNeto,
                'ahorro_reservado' => (float) $ahorroReservadoMes,
                'disponible_para_vivir' => (float) $disponibleParaVivir,
                'status_label' => $statusLabel,
                'status_color' => $statusColor,
                'icon_type' => $iconType,
                'ahorro_mes_pct' => (float) $porcentajeAhorro,
                'porcentaje_ahorro' => (float) $porcentajeAhorro,
                'salud_financiera' => $saludFinanciera,
                'salud_texto' => $saludTexto,
                'has_active_challenge' => (bool) $hasActiveChallenge,
                'ahorro_reservado_mes' => (float) $ahorroReservadoMes,
                'ahorro_protegido_actual' => (float) $ahorroProtegidoActual,
                'dinero_fugado_mes' => (float) $dineroFugadoMes,
                'analisis_usuario' => $analisisUsuario,
                'analisis' => $analisis,
                'distribucion_saldo' => $distribucionSaldo,
            ],
        ];

        Cache::put($cacheKey, $responsePayload, 120); // 2 minutes

        return response()->json($responsePayload);
    }

    /**
     * Get financial summary (with month/year filter).
     */
    public function financialSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $tz = 'America/Bogota';
        $month = (int) $request->query('month', now($tz)->month);
        $year = (int) $request->query('year', now($tz)->year);

        $cacheKey = "fin_summary:{$user->id}:{$year}:{$month}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        $start = Carbon::createFromDate($year, $month, 1, $tz)->startOfMonth()->utc();
        $end = Carbon::createFromDate($year, $month, 1, $tz)->endOfMonth()->utc();

        // Single query for income + expense sums
        $agg = DB::selectOne(
            'SELECT
                COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) AS total_expense
             FROM movements WHERE user_id = ? AND created_at BETWEEN ? AND ?',
            ['income', 'expense', $user->id, $start, $end]
        );

        $totalIncome = (float) ($agg->total_income ?? 0.0);
        $totalExpense = (float) ($agg->total_expense ?? 0.0);

        $totalGoalContributions = 0.0;
        try {
            $totalGoalContributions = (float) ($user->goalContributions()
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount') ?? 0.0);
        } catch (\Throwable) {
            // Relación o tabla aún no migrada — devuelve 0 sin romper la respuesta
        }

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
            ->mapWithKeys(fn ($m) => ($tag = trim((string) ($m->tag_name ?? ''))) !== ''
                ? [$tag => (float) $m->total_amount]
                : []);

        $payload = [
            'status' => 'success',
            'data' => [
                'total_income' => $totalIncome,
                'total_expense' => $totalExpense,
                'total_goal_contributions' => $totalGoalContributions,
                'top_tags' => $topTags,
            ],
        ];

        Cache::put($cacheKey, $payload, 120);

        return response()->json($payload);
    }

    /**
     * Get authenticated user profile (name, phone, country_code, savings config).
     */
    public function getUserProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $hasSavingsCol = SchemaCache::hasColumn('users', 'has_active_challenge');
        $savingsPctCol = SchemaCache::hasColumn('users', 'savings_mode_pct');

        return $this->successResponse([
            'id' => $user->id,
            'name' => $user->name ?? '',
            'phone' => $user->phone ?? '',
            'country_code' => $user->country_code ?? '',
            'email' => $user->email,
            // El panel /admin de la web se muestra solo con rol admin/super-admin
            'roles' => $user->getRoleNames(),
            'has_active_challenge' => $hasSavingsCol ? (bool) $user->has_active_challenge : false,
            'savings_mode_pct' => $savingsPctCol ? (float) ($user->savings_mode_pct ?? 0.0) : 0.0,
        ], 'Perfil del usuario');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generarObservaciones(
        float $incomes,
        float $expenses,
        float $balance,
        float $ahorroMesPct,
        float $eficienciaFiscal,
        ?array $alertaGasto,
        bool $hasActiveChallenge,
        float $ahorroProtegido
    ): array {
        $obs = [];

        if ($incomes > 0) {
            if ($ahorroMesPct >= 30) {
                $obs[] = '💪 Ahorrando el '.$ahorroMesPct.'% de tus ingresos. ¡Disciplina excelente!';
            } elseif ($ahorroMesPct >= 10) {
                $obs[] = '💡 Ahorro actual: '.$ahorroMesPct.'%. Llevar al 20% marca la diferencia.';
            } elseif ($balance < 0) {
                $obs[] = '⚠️ Tus gastos superan tus ingresos. Revisa tus egresos prioritarios.';
            } else {
                $obs[] = '📉 Ahorro bajo este mes. Pequeños recortes acumulan grandes resultados.';
            }
        }

        if ($eficienciaFiscal >= 70) {
            $obs[] = '🧾 El '.$eficienciaFiscal.'% de tus gastos tienen factura. Buen control fiscal.';
        } elseif ($eficienciaFiscal > 0 && $eficienciaFiscal < 40) {
            $obs[] = '🧾 Solo el '.$eficienciaFiscal.'% con factura. Pide comprobante siempre.';
        }

        if ($alertaGasto !== null) {
            if ($alertaGasto['excede_anterior'] === true && $alertaGasto['variacion_pct'] !== null) {
                $obs[] = '📈 "'.$alertaGasto['categoria'].'" subió un '.abs((float) $alertaGasto['variacion_pct']).'% vs el mes anterior.';
            } else {
                $obs[] = '📊 Mayor gasto del mes en "'.$alertaGasto['categoria'].'".';
            }
        }

        if ($hasActiveChallenge && $ahorroProtegido > 0) {
            $obs[] = '🔒 Ahorro reservado de $'.number_format($ahorroProtegido, 0, '.', ',').' está protegido.';
        }

        return array_slice($obs, 0, 3);
    }

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
