<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Handle user login
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request) : JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'phone' => 'required|string|max:15',
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

            if (!password_verify($request->input('password'), $user->password)) {
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
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request) : JsonResponse
    {
        // Validación de datos
        $validated = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            // unique:users,phone asegura que no se repita el teléfono
            'phone' => 'required|string|max:15|unique:users,phone', 
            // digits:4 asegura que sean exactamente 4 números (ej: 1234)
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
            // Nota: Asegúrate de importar Hash al inicio del archivo (use Illuminate\Support\Facades\Hash;)
            $user = User::create([
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
                'password' => \Illuminate\Support\Facades\Hash::make($request->input('password')),
            ]);

            // Crear token de acceso inmediato para que no tenga que loguearse de nuevo
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario registrado exitosamente',
                'data' => [
                    'user' => $user,
                    'token' => $token,
                ],
            ], 201); // 201 Created

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al registrar usuario',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
