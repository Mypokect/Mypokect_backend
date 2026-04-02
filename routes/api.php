<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Budget\BudgetController;
use App\Http\Controllers\Finance\GoalContributionController;
use App\Http\Controllers\Finance\MovementController;
use App\Http\Controllers\Finance\SavingGoalController;
use App\Http\Controllers\Finance\SavingsController;
use App\Http\Controllers\Finance\ScheduledTransactionController;
use App\Http\Controllers\Finance\TaxController;
use App\Http\Controllers\Finance\TransactionController as FinanceTransactionController;
use App\Http\Controllers\Shared\TagController;
use App\Http\Controllers\Shared\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return response()->json(['message' => 'Welcome to the Finance API']);
});

// Ruta de fallback para errores de autenticación
Route::get('/error', function () {
    return response()->json(['error' => 'Unauthorized or Server Error'], 401);
})->name('login');

// --- AUTENTICACIÓN ---
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:10,1');

// --- RUTAS PROTEGIDAS (Sanctum) ---
Route::middleware('auth:sanctum')->group(function () {

    // 1. HOME & USUARIO
    Route::get('/home-data', [AuthController::class, 'homeData']);
    Route::get('/financial-summary', [AuthController::class, 'financialSummary']);

    // 2. MOVIMIENTOS
    Route::get('/movements', [MovementController::class, 'index']);
    Route::post('/movements', [MovementController::class, 'store']);

    // IA de Voz para Movimientos (Entiende gasto/ingreso + monto + metodo de pago)
    Route::post('/movements/sugerir-voz', [MovementController::class, 'suggestFromVoice'])
        ->middleware('throttle:20,1');

    // 3. ETIQUETAS (TAGS)
    Route::get('/tags', [TagController::class, 'index']);
    Route::post('/tags/create', [TagController::class, 'store']);
    Route::post('/tags/suggestion', [TagController::class, 'suggest'])
        ->middleware('throttle:20,1');

    // 4. CALENDARIO & PROGRAMADOS
    Route::prefix('v1/calendar')->group(function () {
        Route::apiResource('reminders', ReminderController::class)->except(['create', 'edit']);
        Route::post('/reminders/{reminder}/mark-paid', [ReminderController::class, 'markAsPaid']);
    });

    Route::apiResource('scheduled-transactions', ScheduledTransactionController::class);
    Route::post('/scheduled-transactions/{scheduledTransaction}/toggle-paid', [ScheduledTransactionController::class, 'togglePaidStatus']);
    // (Opcional si usas lógica de transacciones complejas)
    Route::post('/transactions/{transaction}/confirm', [TransactionController::class, 'confirmPayment']);

    // 5. ASISTENTES FINANCIEROS
    // Análisis de ahorro mensual (50/30/20)
    Route::get('/savings/analyze', [SavingsController::class, 'analyze']);
    // Guardar modo de ahorro elegido por el usuario
    Route::post('/savings/save-plan',    [SavingsController::class, 'savePlan']);
    // Cancelar el reto de ahorro activo
    Route::post('/savings/cancel-plan',  [SavingsController::class, 'cancelPlan']);

    // 6. IMPUESTOS (RADAR Y DECLARACIÓN)
    Route::get('/taxes/data',          [TaxController::class, 'getData']);        // Datos pre-llenados + cálculo por defecto
    Route::get('/taxes/alerts',        [TaxController::class, 'checkLimits']);    // Semáforo Fiscal (año ?year=)
    Route::get('/taxes/profile',       [TaxController::class, 'getProfile']);     // Perfil fiscal del usuario
    Route::post('/taxes/profile',      [TaxController::class, 'saveProfile']);    // Guardar / actualizar perfil
    Route::post('/taxes/recalculate',  [TaxController::class, 'recalculate']);    // Simulador interactivo (Flutter envía params, backend calcula)

    // 7. PRESUPUESTOS INTELIGENTES (Smart Budget)
    Route::get('/budgets', [BudgetController::class, 'getBudgets']);
    Route::get('/budgets/{budget}', [BudgetController::class, 'getBudget']);

    // Rutas de creación
    Route::post('/budgets/manual', [BudgetController::class, 'createManualBudget']);
    Route::post('/budgets/ai/generate', [BudgetController::class, 'generateAIBudget'])->middleware('throttle:10,1');
    Route::post('/budgets/ai/save', [BudgetController::class, 'saveAIBudget']);

    // Gestión del presupuesto
    Route::put('/budgets/{budget}', [BudgetController::class, 'updateBudget']);
    Route::delete('/budgets/{budget}', [BudgetController::class, 'deleteBudget']);
    Route::post('/budgets/{budget}/validate', [BudgetController::class, 'validateBudget']);
    Route::get('/budgets/{budget}/spending', [BudgetController::class, 'getSpending']);
    Route::get('/budgets/{budget}/suggested-tags', [BudgetController::class, 'getSuggestedTags'])->middleware('throttle:20,1');
    Route::delete('/budgets/{budget}/suggested-tags-cache', [BudgetController::class, 'clearSuggestedTagsCache']);
    Route::post('/budgets/{budget}/apply-ai-tags', [BudgetController::class, 'applyAITags']);
    Route::post('/budgets/{budget}/move-movement', [BudgetController::class, 'moveMovement']);
    Route::post('/budgets/{budget}/reactivate', [BudgetController::class, 'reactivateBudget']);
    Route::post('/budgets/{budget}/duplicate', [BudgetController::class, 'duplicateBudget']);

    // Categorías dentro del presupuesto
    Route::post('/budgets/{budget}/categories', [BudgetController::class, 'addCategory']);
    Route::put('/budgets/{budget}/categories/{category}', [BudgetController::class, 'updateCategory']);
    Route::delete('/budgets/{budget}/categories/{category}', [BudgetController::class, 'deleteCategory']);

    // Voz específica para crear Presupuestos (No confundir con la de movimientos)
    Route::post('/process-voice', [BudgetController::class, 'processVoiceCommand']);

    // 8. METAS DE AHORRO (Saving Goals)
    Route::get('/saving-goals', [SavingGoalController::class, 'index']);
    Route::post('/saving-goals', [SavingGoalController::class, 'store']);
    Route::get('/saving-goals/{saving_goal}', [SavingGoalController::class, 'show']);
    Route::put('/saving-goals/{saving_goal}', [SavingGoalController::class, 'update']);
    Route::delete('/saving-goals/{saving_goal}', [SavingGoalController::class, 'destroy']);

    // 9. GOAL CONTRIBUTIONS (Abonos a Metas)
    Route::get('/goal-contributions/{goalId}', [GoalContributionController::class, 'index']);
    Route::post('/goal-contributions', [GoalContributionController::class, 'store']);
    Route::delete('/goal-contributions/{contributionId}', [GoalContributionController::class, 'destroy']);
    Route::get('/goal-contributions/{goalId}/stats', [GoalContributionController::class, 'stats']);

    // 10. UNIFIED TRANSACTIONS (Vista Unificada)
    Route::get('/transactions/unified', [FinanceTransactionController::class, 'unified']);
});