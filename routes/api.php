<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\TagController;
// --- 1. AÑADE EL IMPORT DEL NUEVO CONTROLADOR ---
use App\Http\Controllers\ScheduledTransactionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\SavingsController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\SmartBudgetController; 
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
    Route::get('/home-data', [AuthController::class, 'homeData']);
    Route::get('/taxes/data', [TaxController::class, 'getData']);
    
    // ===== BUDGET ROUTES (MODO 1: Manual & MODO 2: AI) =====
    // Get all user budgets
    Route::get('/budgets', [SmartBudgetController::class, 'getBudgets']);
    
    // Get single budget with categories
    Route::get('/budgets/{budget}', [SmartBudgetController::class, 'getBudget']);
    
    // Create manual budget (MODO 1)
    Route::post('/budgets/manual', [SmartBudgetController::class, 'createManualBudget']);
    
    // Generate AI suggestions (MODO 2 - step 1)
    Route::post('/budgets/ai/generate', [SmartBudgetController::class, 'generateAIBudget'])
        ->middleware('throttle:10,1');
    
    // Save AI-generated budget (MODO 2 - step 2)
    Route::post('/budgets/ai/save', [SmartBudgetController::class, 'saveAIBudget']);
    
    // Update budget
    Route::put('/budgets/{budget}', [SmartBudgetController::class, 'updateBudget']);
    
    // Delete budget
    Route::delete('/budgets/{budget}', [SmartBudgetController::class, 'deleteBudget']);
    
    // Validate budget (check if categories sum equals total)
    Route::post('/budgets/{budget}/validate', [SmartBudgetController::class, 'validateBudget']);
    
    // ===== CATEGORY MANAGEMENT =====
    // Add category to budget
    Route::post('/budgets/{budget}/categories', [SmartBudgetController::class, 'addCategory']);
    
    // Update category
    Route::put('/budgets/{budget}/categories/{category}', [SmartBudgetController::class, 'updateCategory']);
    Route::post('/process-voice', [SmartBudgetController::class, 'processVoiceCommand']);
    // Delete category
    Route::delete('/budgets/{budget}/categories/{category}', [SmartBudgetController::class, 'deleteCategory']);
});