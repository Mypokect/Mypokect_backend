<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    use ApiResponse;
    /**
     * Handle user login
     */
    public function login(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'password' => 'required|digits:4',
        ]);

        if ($validated->fails()) {
            return $this->validationErrorResponse($validated->errors(), 'Datos de inicio de sesión inválidos');
        }

        try {
            $user = User::where('phone', $request->input('phone'))->first();

            if (! $user) {
                return $this->notFoundResponse('Credenciales no válidas');
            }

            if (! Hash::check($request->input('password'), $user->password)) {
                return $this->errorResponse('Contraseña incorrecta', 401);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->successResponse([
                'user' => $user,
                'token' => $token,
            ], 'Inicio de sesión exitoso');

        } catch (\Exception $e) {
            return $this->errorResponse('Error al iniciar sesión', 500, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle user registration
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
            return $this->errorResponse('Error al registrar usuario', 500, ['error' => $e->getMessage()]);
        }
    }

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
     * Obtener resumen financiero detallado del mes actual
     * Retorna: ingresos, gastos, aportes a metas y etiquetas más frecuentes
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
