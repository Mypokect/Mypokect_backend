<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * Login.
     *
     * Authenticates user with phone number and 4-digit PIN. Returns a Sanctum bearer token.
     * Implements per-account rate limiting: 5 failed attempts locks the account for 10 minutes.
     *
     * @bodyParam phone string required User's phone number. Example: 3001234567
     * @bodyParam password string required 4-digit PIN. Example: 1234
     * @unauthenticated
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

        // Rate limiting per account (phone number), not just per IP
        $throttleKey = 'login_attempt:' . Str::lower($request->input('phone'));

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return $this->errorResponse(
                "Cuenta bloqueada temporalmente. Intente en {$seconds} segundos.",
                429
            );
        }

        try {
            $user = User::where('phone', $request->input('phone'))->first();

            // Unified error: same message for "user not found" and "wrong password"
            // to prevent user enumeration attacks
            if (! $user || ! Hash::check($request->input('password'), $user->password)) {
                RateLimiter::hit($throttleKey, 600); // Lock for 10 minutes after 5 failures

                return $this->errorResponse('Credenciales inválidas', 401);
            }

            // Success: clear rate limiter
            RateLimiter::clear($throttleKey);

            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->successResponse([
                'user' => $user,
                'token' => $token,
            ], 'Inicio de sesión exitoso');

        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Register.
     *
     * Creates a new user account and returns a Sanctum bearer token.
     *
     * @bodyParam name string required User's display name. Example: Carlos
     * @bodyParam phone string required Phone number (unique). Example: 3001234567
     * @bodyParam country_code string required Country dial code. Example: +57
     * @bodyParam password string required 4-digit PIN. Example: 1234
     * @unauthenticated
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

            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->createdResponse([
                'user' => $user,
                'token' => $token,
            ], 'Usuario registrado exitosamente');

        } catch (\Exception $e) {
            Log::error('Register error: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Get home data.
     *
     * Returns the user's name, current balance, and financial status indicator (color + label).
     */
    public function homeData(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Sumar Ingresos y Gastos
        $incomes = $user->movements()->where('type', 'income')->sum('amount');
        $expenses = $user->movements()->where('type', 'expense')->sum('amount');

        $balance = $incomes - $expenses;

        // 2. Lógica para definir el Estado (Semáforo)
        // Valores por defecto (Caso: Usuario Nuevo / Sin datos)
        $statusLabel = 'Sin actividad';
        $statusColor = 'grey';
        $iconType = 'neutral';

        // Si hay movimientos, calculamos la realidad
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

        return $this->successResponse([
            'name' => $user->name,
            'balance' => $balance,
            'status_label' => $statusLabel,
            'status_color' => $statusColor,
            'icon_type' => $iconType,
        ]);
    }

    /**
     * Get financial summary.
     *
     * Returns current month's income, expenses, goal contributions, and top 5 tags by amount.
     */
    public function financialSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        // Obtener el primer y último día del mes actual
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        // 1. Calcular ingresos del mes actual (solo de movements)
        $totalIncome = $user->movements()
            ->where('type', 'income')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        // 2. Calcular gastos del mes actual (solo de movements, NO incluye aportes a metas)
        $totalExpense = $user->movements()
            ->where('type', 'expense')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        // 3. Calcular aportes a metas del mes actual (de goal_contributions)
        $totalGoalContributions = $user->goalContributions()
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        // 4. Obtener top 5 etiquetas más usadas con sus montos totales (del mes actual, solo movements)
        $topTags = $user->movements()
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereNotNull('tag_id')
            ->with('tag:id,name')
            ->selectRaw('tag_id, SUM(amount) as total_amount')
            ->groupBy('tag_id')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->mapWithKeys(function ($movement) {
                return [$movement->tag->name => (float) $movement->total_amount];
            });

        return $this->successResponse([
            'total_income' => (float) $totalIncome,
            'total_expense' => (float) $totalExpense,
            'total_goal_contributions' => (float) $totalGoalContributions,
            'top_tags' => $topTags,
        ]);
    }
}
