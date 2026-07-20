<?php

use App\Http\Controllers\Api\V1\Admin\AdminAuthController;
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

// Login del panel de administración (2 pasos: clave + código SMS).
// admin.gate: sin la llave secreta X-Admin-Gate, estas rutas devuelven 404.
Route::middleware('admin.gate')->group(function () {
    Route::post('/admin/auth/login', [AdminAuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/admin/auth/verify', [AdminAuthController::class, 'verify'])->middleware('throttle:10,1');
});

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

    // Analítica de producto: la web reporta cada visita de sección
    // (page_view). El panel admin agrega estos eventos para ver el tráfico.
    Route::post('/analytics/track', function (\Illuminate\Http\Request $request) {
        $validated = $request->validate(['section' => ['required', 'string', 'max:40']]);
        \App\Models\AnalyticsEvent::create([
            'user_id'     => $request->user()->id,
            'event'       => 'page_view',
            'properties'  => ['section' => $validated['section']],
            'occurred_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    })->middleware('throttle:240,1');

    // Novedades publicadas (visibles para todos los usuarios de la app)
    Route::get('/announcements', fn () => response()->json([
        'data' => Announcement::where('is_published', true)
            ->latest('published_at')->limit(20)
            ->get(['id', 'title', 'body', 'type', 'published_at']),
    ]));

    // ── Panel de administración ───────────────────────────────────────
    // Tres candados: llave secreta (admin.gate, si no → 404), rol admin y
    // el token `admin_web` emitido solo tras verificar el código SMS.
    Route::prefix('admin')->middleware(['admin.gate', 'role:admin|super-admin', 'admin.session'])->group(function () {
        Route::get('/overview', [AdminController::class, 'overview']);
        Route::get('/analytics', [AdminController::class, 'analytics']);
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
