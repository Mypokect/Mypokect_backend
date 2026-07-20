<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Models\Announcement;
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

    // Prueba gratis de 14 días (una vez por usuario; el registro la crea solo)
    Route::post('/subscription/start-trial', [SubscriptionController::class, 'startTrial'])->middleware('throttle:5,1');

    // Checkout hosted de Wompi (fallback: PSE / Nequi / redirección)
    Route::post('/subscription/checkout', [CheckoutController::class, 'create']);

    // Débito automático (tarjeta guardada / tokenización)
    Route::get('/subscription/acceptance', [PaymentController::class, 'acceptance']);
    Route::post('/subscription/pay-with-card', [PaymentController::class, 'payWithCard']);
    Route::post('/subscription/auto-renew', [PaymentController::class, 'autoRenew']);

    // Método de pago guardado (para "Visa ****1234" y su eliminación)
    Route::get('/payment-method', [PaymentController::class, 'paymentMethod']);
    Route::delete('/payment-method', [PaymentController::class, 'destroyPaymentMethod']);

    // Novedades publicadas (visibles para todos los usuarios de la app)
    Route::get('/announcements', fn () => response()->json([
        'data' => Announcement::where('is_published', true)
            ->latest('published_at')->limit(20)
            ->get(['id', 'title', 'body', 'type', 'published_at']),
    ]));

    // ── Panel de administración (solo admin / super-admin) ────────────
    Route::prefix('admin')->middleware('role:admin|super-admin')->group(function () {
        Route::get('/overview', [AdminController::class, 'overview']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users/{user}/premium', [AdminController::class, 'setPremium']);
        Route::get('/payments', [AdminController::class, 'payments']);
        Route::get('/plans', [AdminController::class, 'plans']);
        Route::put('/plans/{plan}', [AdminController::class, 'updatePlan']);
        Route::get('/announcements', [AdminController::class, 'announcements']);
        Route::post('/announcements', [AdminController::class, 'storeAnnouncement']);
        Route::put('/announcements/{announcement}', [AdminController::class, 'updateAnnouncement']);
        Route::delete('/announcements/{announcement}', [AdminController::class, 'destroyAnnouncement']);
    });
});
