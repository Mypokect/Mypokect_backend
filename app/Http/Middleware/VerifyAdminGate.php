<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compuerta secreta del panel de administración.
 *
 * Toda petición a /admin/* (incluido el login) debe traer el header
 * X-Admin-Gate con la llave ADMIN_GATE_KEY del .env. Si falta o no
 * coincide respondemos 404 — para un extraño el panel NO existe.
 *
 * Fail-closed: si la llave no está configurada, nadie entra.
 */
class VerifyAdminGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.admin.gate_key');
        $provided = (string) $request->header('X-Admin-Gate', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(404);
        }

        return $next($request);
    }
}
