<?php

use App\Http\Controllers\Api\V1\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (nuevo)
|--------------------------------------------------------------------------
| Prefijo: /api/v1
|
| Convención de la plataforma SaaS multiplataforma. Aquí viven las rutas
| NUEVAS (billing, suscripciones). Las rutas legacy de Flutter siguen en
| routes/api.php (/api/*) SIN cambios para no romper la app móvil.
|
| A medida que avancen las fases, los endpoints de dominio (movements,
| budgets, ...) se irán reexponiendo bajo /api/v1 reutilizando los mismos
| controllers.
*/

Route::get('/', fn () => response()->json(['message' => 'Finance API v1']));

// Catálogo público de planes (para landing / pricing)
Route::get('/plans', [SubscriptionController::class, 'plans']);

Route::middleware(['auth:sanctum', 'throttle:200,1'])->group(function () {
    // Estado de suscripción — fuente de verdad para gating en todos los clientes
    Route::get('/subscription/status', [SubscriptionController::class, 'status']);

    // Ejemplo de ruta premium (gated). Descomentar cuando exista el endpoint:
    // Route::post('/movements/sugerir-voz', [MovementController::class, 'suggestFromVoice'])
    //     ->middleware('subscription.active');
});
