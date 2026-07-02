<?php

use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\PaymentController;
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

    // Checkout hosted de Wompi (fallback: PSE / Nequi / redirección)
    Route::post('/subscription/checkout', [CheckoutController::class, 'create']);

    // Débito automático (tarjeta guardada / tokenización)
    Route::get('/subscription/acceptance', [PaymentController::class, 'acceptance']);
    Route::post('/subscription/pay-with-card', [PaymentController::class, 'payWithCard']);
    Route::post('/subscription/auto-renew', [PaymentController::class, 'autoRenew']);

    // Método de pago guardado (para "Visa ****1234" y su eliminación)
    Route::get('/payment-method', [PaymentController::class, 'paymentMethod']);
    Route::delete('/payment-method', [PaymentController::class, 'destroyPaymentMethod']);
});
