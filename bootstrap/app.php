<?php

use App\Http\Middleware\EnsureSubscriptionActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // API v1 (nuevo) — clientes Vue/web + billing. Prefijo /api/v1
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_v1.php'));

            // Webhooks de pasarelas — prefijo /api, sin auth de usuario
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Gating de features premium + roles del panel de administración
        $middleware->alias([
            'subscription.active' => EnsureSubscriptionActive::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'admin.session' => \App\Http\Middleware\EnsureAdminSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? 60;
                return response()->json([
                    'status'     => 'error',
                    'message'    => "Demasiadas solicitudes. Intenta de nuevo en {$retryAfter} segundos.",
                    'error_type' => 'rate_limited',
                    'retry_after' => (int) $retryAfter,
                ], 429);
            }
        });
    })->create();
