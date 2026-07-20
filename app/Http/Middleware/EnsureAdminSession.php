<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las rutas /admin solo aceptan el token `admin_web`, que se emite únicamente
 * tras el login de administrador con verificación por SMS. Un token de la app
 * normal — aunque sea del mismo usuario admin — no sirve: así el panel exige
 * SIEMPRE el segundo factor.
 */
class EnsureAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->currentAccessToken()?->name !== 'admin_web') {
            return response()->json([
                'message' => 'Esta sección requiere iniciar sesión como administrador.',
                'error_type' => 'admin_2fa_required',
            ], 403);
        }

        return $next($request);
    }
}
