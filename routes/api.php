<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\TagController;
// --- 1. AÑADE EL IMPORT DEL NUEVO CONTROLADOR ---
use App\Http\Controllers\ScheduledTransactionController;
use App\Http\Controllers\TransactionController;

Route::get('/', function () {
    return response()->json(['message' => 'Welcome to the Finance API']);
});

Route::get('/error', function () {
    return response()->json(['error' => 'error into the server'], 500);
})->name('login');

// Rutas de autenticación
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:5,1');
// Rutas // Movimientos
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/movements', [MovementController::class, 'index']);
    Route::post('/movements', [MovementController::class, 'create']);
    Route::post('/movements/sugerir-voz', [MovementController::class, 'sugerirMovimientoConIA'])
         ->middleware('throttle:5,1'); // opcional: limitar llamadas a la IA
    Route::post('/tags/create', [TagController::class, 'store']);
    Route::post('/tags/suggestion', [TagController::class, 'sugerirDesdeIA']);
    Route::get('/tags', [TagController::class, 'index']);

    // --- 2. AÑADE ESTAS DOS LÍNEAS PARA LA FUNCIÓN DEL CALENDARIO ---
    
    // `apiResource` crea automáticamente todas las rutas CRUD para las transacciones programadas.
    // GET    /scheduled-transactions
    // POST   /scheduled-transactions
    // GET    /scheduled-transactions/{id}
    // PUT    /scheduled-transactions/{id}
    // DELETE /scheduled-transactions/{id}
    Route::apiResource('scheduled-transactions', ScheduledTransactionController::class);

    // Ruta específica para marcar una ocurrencia de un pago como "completado".
    Route::post('/scheduled-transactions/{scheduledTransaction}/toggle-paid', [ScheduledTransactionController::class, 'togglePaidStatus']);
    Route::post('/transactions/{transaction}/confirm', [TransactionController::class, 'confirmPayment']);
    Route::get('/savings/analyze', [SavingsController::class, 'analyze']);
});