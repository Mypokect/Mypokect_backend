<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Login EXCLUSIVO del panel de administración, en dos pasos:
 *
 *  1. POST /v1/admin/auth/login   (teléfono + clave, y el usuario debe tener
 *     rol admin/super-admin) → genera un código de 6 dígitos y lo envía por
 *     SMS al teléfono registrado.
 *  2. POST /v1/admin/auth/verify  (teléfono + código) → emite el token
 *     Sanctum `admin_web`, el ÚNICO que las rutas /admin aceptan.
 *
 * El envío real de SMS usa el mismo patrón que la recuperación de clave:
 * en local/staging el código se loguea y se devuelve como `debug_code`;
 * en producción hay que conectar el proveedor de SMS (mismo TODO).
 */
class AdminAuthController extends Controller
{
    private const CODE_TTL_SECONDS = 300; // 5 minutos
    private const MAX_VERIFY_ATTEMPTS = 5;

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone'    => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = 'admin_login:'.Str::lower($validated['phone']);
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json(['message' => "Demasiados intentos. Espera {$seconds} segundos."], 429);
        }

        $user = User::where('phone', $validated['phone'])->first();

        // Respuesta idéntica para clave mala, usuario inexistente o sin rol:
        // no filtrar cuáles teléfonos son de administradores.
        if (! $user
            || ! Hash::check($validated['password'], $user->password)
            || ! $user->hasAnyRole(['admin', 'super-admin'])) {
            RateLimiter::hit($throttleKey, 600);

            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        RateLimiter::clear($throttleKey);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->cacheKey($user->phone), [
            'user_id'  => $user->id,
            'code'     => $code,
            'attempts' => 0,
        ], now()->addSeconds(self::CODE_TTL_SECONDS));

        // TODO producción: enviar por el proveedor de SMS real.
        Log::info('Admin 2FA code generated', ['user_id' => $user->id, 'code' => $code]);

        $data = [
            'phone_mask' => substr($user->phone, 0, 3).'•••'.substr($user->phone, -2),
            'expires_in' => self::CODE_TTL_SECONDS,
        ];
        if (! app()->isProduction()) {
            $data['debug_code'] = $code;
        }

        return response()->json(['data' => $data]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code'  => ['required', 'digits:6'],
        ]);

        $key = $this->cacheKey($validated['phone']);
        $payload = Cache::get($key);

        if (! is_array($payload)) {
            return response()->json(['message' => 'El código expiró. Vuelve a iniciar sesión.'], 422);
        }

        if ($payload['attempts'] >= self::MAX_VERIFY_ATTEMPTS) {
            Cache::forget($key);

            return response()->json(['message' => 'Demasiados intentos. Vuelve a iniciar sesión.'], 429);
        }

        if (! hash_equals((string) $payload['code'], (string) $validated['code'])) {
            $payload['attempts']++;
            Cache::put($key, $payload, now()->addSeconds(self::CODE_TTL_SECONDS));

            return response()->json(['message' => 'Código incorrecto.'], 422);
        }

        Cache::forget($key);

        $user = User::findOrFail($payload['user_id']);

        // Un solo acceso admin vigente a la vez: revoca tokens admin previos.
        $user->tokens()->where('name', 'admin_web')->delete();
        $token = $user->createToken('admin_web')->plainTextToken;

        return response()->json(['data' => [
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'roles' => $user->getRoleNames(),
            ],
        ]]);
    }

    private function cacheKey(string $phone): string
    {
        return 'admin_2fa:'.Str::lower(trim($phone));
    }
}
