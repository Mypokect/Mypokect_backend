<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gating de features premium. Devuelve 402 (Payment Required) si el usuario
 * no tiene una suscripción que dé acceso premium (trialing/active y sin vencer).
 *
 * Uso en rutas: ->middleware('subscription.active')
 */
class EnsureSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isPremium()) {
            return response()->json([
                'error'   => 'subscription_required',
                'message' => 'Esta función requiere una suscripción activa.',
            ], 402);
        }

        return $next($request);
    }
}
