<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash; // Importamos Hash para usarlo más limpio

class AuthController extends Controller
{
    /**
     * Handle user login
     */
    public function login(Request $request) : JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'phone' => 'required|string|max:20', // Aumenté a 20 por si el prefijo es largo
            'password' => 'required|digits:4',
        ]);

        if ($validated->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de inicio de sesión inválidos',
                'errors' => $validated->errors(),
            ], 422);
        }

        try {
            $user = User::where('phone', $request->input('phone'))->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Credenciales no válidas',
                ], 404);
            }

            if (!Hash::check($request->input('password'), $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contraseña incorrecta',
                ], 401);
            }

            $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json([
                'status' => 'success',
                'message' => 'Inicio de sesión exitoso',
                'data' => [
                    'user' => $user,
                    'token' => $token,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al iniciar sesión',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle user registration
     */
    public function register(Request $request) : JsonResponse
    {
        // Validación de datos
        $validated = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:users,phone',
            // --- 1. VALIDACIÓN NUEVA ---
            'country_code' => 'required|string|max:5', 
            // ---------------------------
            'password' => 'required|digits:4', 
        ]);

        if ($validated->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de registro inválidos',
                'errors' => $validated->errors(),
            ], 422);
        }

        try {
            // Crear el usuario
            $user = User::create([
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
                // --- 2. GUARDAR EL DATO NUEVO ---
                'country_code' => $request->input('country_code'),
                // --------------------------------
                'password' => Hash::make($request->input('password')),
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario registrado exitosamente',
                'data' => [
                    'user' => $user,
                    'token' => $token,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al registrar usuario',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
      public function homeData(Request $request) : JsonResponse
    {
        $user = $request->user();

        // 1. Sumar Ingresos y Gastos
        $incomes = $user->movements()->where('type', 'income')->sum('amount');
        $expenses = $user->movements()->where('type', 'expense')->sum('amount');
        
        $balance = $incomes - $expenses;

        // 2. Lógica para definir el Estado (Semáforo)
        // Valores por defecto (Caso: Usuario Nuevo / Sin datos)
        $statusLabel = "Sin actividad";
        $statusColor = "grey";
        $iconType = "neutral"; // Ni sube ni baja

        // Si hay movimientos, calculamos la realidad
        if ($incomes > 0 || $expenses > 0) {
            if ($balance >= 0) {
                $statusLabel = "Saldo a favor";
                $statusColor = "green";
                $iconType = "up";
            } else {
                $statusLabel = "Sobregirado";
                $statusColor = "red";
                $iconType = "down";
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'name' => $user->name,
                'balance' => $balance,
                // ESTOS SON LOS DATOS QUE FALTABAN:
                'status_label' => $statusLabel,
                'status_color' => $statusColor,
                'icon_type' => $iconType,
            ]
        ], 200);
    }
}