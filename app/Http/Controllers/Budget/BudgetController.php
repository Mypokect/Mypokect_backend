<?php

namespace App\Http\Controllers\Budget;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Services\BudgetAIService;
use App\Services\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BudgetController extends Controller
{
    use ApiResponse;
    private BudgetAIService $aiService;
    private BudgetService $budgetService;

    public function __construct(BudgetAIService $aiService, BudgetService $budgetService)
    {
        $this->aiService = $aiService;
        $this->budgetService = $budgetService;
    }

    /**
     * Get all budgets for the authenticated user.
     */
    /**
     * Get all budgets for the authenticated user.
     */
    public function getBudgets(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // LOG: Para ver en laravel.log si entra aquí
            Log::info("User {$user->id} is requesting budgets list.");

            // 1. ELIMINAMOS EL FILTRO DE STATUS PARA QUE MUESTRE TODO
            // 2. USAMOS get() EN LUGAR DE paginate() PARA SIMPLIFICAR EL JSON
            $budgets = Budget::where('user_id', $user->id)
                ->with('categories')
                ->orderBy('created_at', 'desc')
                ->get(); // <--- IMPORTANTE: get() devuelve la lista directa

            Log::info('Found '.$budgets->count().' budgets.');

            return $this->successResponse($budgets);

        } catch (\Exception $e) {
            Log::error('Error fetching budgets: '.$e->getMessage());
            return $this->errorResponse('Error fetching budgets: ' . $e->getMessage());
        }
    }

    /**
     * Get a specific budget with all its categories.
     */
    public function getBudget(Budget $budget): JsonResponse
    {
        try {
            Log::info("SmartBudget: Fetching specific budget ID: {$budget->id}");

            if ($budget->user_id !== Auth::id()) {
                Log::warning("SmartBudget: Unauthorized access attempt to budget {$budget->id} by user ".Auth::id());
                return $this->unauthorizedResponse();
            }

            $budget->load('categories');

            return $this->successResponse([
                'budget' => $budget,
                'is_valid' => $budget->isValid(),
                'categories_total' => $budget->getCategoriesTotal(),
            ]);
        } catch (\Exception $e) {
            Log::error('SmartBudget: Error fetching single budget: '.$e->getMessage());
            return $this->errorResponse('Error fetching budget: ' . $e->getMessage());
        }
    }

    /**
     * Create a manual budget (MODO 1: MANUAL).
     */
    public function createManualBudget(Request $request): JsonResponse
    {
        Log::info('SmartBudget: Attempting to create MANUAL budget.', $request->all());

        $validated = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'total_amount' => 'required|numeric|min:0.01',
            'categories' => 'required|array|min:1',
            'categories.*.name' => 'required|string|max:255',
            'categories.*.amount' => 'required|numeric|min:0.01',
            'categories.*.reason' => 'nullable|string|max:500',
        ]);

        if ($validated->fails()) {
            Log::warning('SmartBudget: Validation failed for manual budget.', $validated->errors()->toArray());
            return $this->validationErrorResponse($validated->errors());
        }

        try {
            $user = Auth::user();

            $budget = $this->budgetService->createManualBudget($user, $request->only([
                'title', 'description', 'total_amount', 'categories'
            ]));

            return $this->createdResponse([
                'budget' => $budget,
                'is_valid' => $budget->isValid(),
            ], 'Budget created successfully');

        } catch (\InvalidArgumentException $e) {
            return $this->validationErrorResponse(null, $e->getMessage());
        } catch (\Exception $e) {
            Log::error('SmartBudget: Critical Error creating manual budget: '.$e->getMessage());
            return $this->errorResponse('Error creating budget: ' . $e->getMessage());
        }
    }

    /**
     * Generate budget with AI suggestions (MODO 2: IA).
     */
    public function generateAIBudget(Request $request): JsonResponse
    {
        Log::info('SmartBudget: Starting AI Generation request.', $request->only(['title', 'total_amount']));

        try {
            $validated = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'description' => 'required|string|max:2000',
                'total_amount' => 'required|numeric|min:0.01',
            ]);

            if ($validated->fails()) {
                Log::warning('SmartBudget: AI Input Validation failed.', $validated->errors()->toArray());
                return $this->validationErrorResponse($validated->errors());
            }

            $title = $request->input('title');
            $description = $request->input('description');
            $totalAmount = (float) $request->input('total_amount');

            Log::info('SmartBudget: Calling AI Service...');
            $aiResult = $this->aiService->generateBudgetWithAI($title, $totalAmount, $description);

            if (! $aiResult['success']) {
                Log::error('SmartBudget: AI Service failed to return success.');
                throw new \Exception('Failed to generate AI suggestions');
            }

            Log::info('SmartBudget: AI Service returned success. Parsing data.');
            $budgetData = $aiResult['data'];

            return $this->successResponse([
                'title' => $title,
                'description' => $description,
                'total_amount' => $totalAmount,
                'categories' => $budgetData['categories'] ?? [],
                'general_advice' => $budgetData['general_advice'] ?? '',
                'language' => $aiResult['language'],
                'plan_type' => $aiResult['plan_type'],
                'note' => 'These are AI suggestions. You can edit, add, or remove categories before saving.',
            ], 'AI suggestions generated. Review and save as budget.');

        } catch (\Exception $e) {
            Log::error('SmartBudget: Error in generateAIBudget: '.$e->getMessage());
            return $this->errorResponse('Error generating AI suggestions: ' . $e->getMessage());
        }
    }

    /**
     * Save an AI-generated budget (user confirms after reviewing).
     */
    public function saveAIBudget(Request $request): JsonResponse
    {
        Log::info('SmartBudget: Saving AI Generated Budget.', ['title' => $request->input('title')]);

        $validated = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'total_amount' => 'required|numeric|min:0.01',
            'categories' => 'required|array|min:1',
            'categories.*.name' => 'required|string|max:255',
            'categories.*.amount' => 'required|numeric|min:0.01',
            'categories.*.reason' => 'nullable|string|max:500',
            'language' => 'nullable|string|max:10',
            'plan_type' => 'nullable|string|in:travel,event,party,purchase,project,other',
        ]);

        if ($validated->fails()) {
            Log::warning('SmartBudget: Save AI Validation failed.', $validated->errors()->toArray());
            return $this->validationErrorResponse($validated->errors());
        }

        try {
            $user = Auth::user();

            $budget = $this->budgetService->createAIBudget($user, $request->only([
                'title', 'description', 'total_amount', 'categories', 'language', 'plan_type'
            ]));

            return $this->createdResponse([
                'budget' => $budget,
                'is_valid' => $budget->isValid(),
            ], 'AI budget saved successfully');

        } catch (\InvalidArgumentException $e) {
            return $this->validationErrorResponse(null, $e->getMessage());
        } catch (\Exception $e) {
            Log::error('SmartBudget: Error saving AI budget: '.$e->getMessage());
            return $this->errorResponse('Error saving budget: ' . $e->getMessage());
        }
    }

    /**
     * Update a budget.
     */
    public function updateBudget(Request $request, Budget $budget): JsonResponse
    {
        // Usamos una transacción para que todo se guarde o nada se guarde (seguridad de datos)
        return DB::transaction(function () use ($request, $budget) {
            try {
                if ($budget->user_id !== Auth::id()) {
                    return $this->unauthorizedResponse();
                }

                $validated = Validator::make($request->all(), [
                    'title' => 'required|string|max:255',
                    'description' => 'nullable|string|max:2000',
                    'total_amount' => 'required|numeric|min:0.01',
                    'categories' => 'required|array|min:1', // Ahora exigimos las categorías
                    'categories.*.id' => 'nullable|integer', // Si tiene ID es edición, si no, es nuevo
                    'categories.*.name' => 'required|string|max:255',
                    'categories.*.amount' => 'required|numeric|min:0.01',
                ]);

                if ($validated->fails()) {
                    return $this->validationErrorResponse($validated->errors());
                }

                $budget = $this->budgetService->updateBudget($budget, $request->only([
                    'title', 'description', 'total_amount', 'categories'
                ]));

                return $this->successResponse($budget, 'Budget and categories updated successfully');

            } catch (\Exception $e) {
                Log::error('Error updating budget: '.$e->getMessage());
                throw $e; // Hace rollback de la transacción
            }
        });
    }

    /**
     * Delete a budget and all its categories.
     */
    public function deleteBudget(Budget $budget): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            $this->budgetService->deleteBudget($budget);

            return $this->deletedResponse('Budget deleted');

        } catch (\Exception $e) {
            Log::error('SmartBudget: Error deleting budget: '.$e->getMessage());
            return $this->errorResponse('Error deleting budget: ' . $e->getMessage());
        }
    }

    /**
     * Add a category to an existing budget.
     */
    public function addCategory(Request $request, Budget $budget): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            $validated = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0.01',
                'reason' => 'nullable|string|max:500',
            ]);

            if ($validated->fails()) {
                return $this->validationErrorResponse($validated->errors());
            }

            $category = $this->budgetService->addCategory($budget, $request->only([
                'name', 'amount', 'reason'
            ]));

            return $this->createdResponse($category, 'Category added');

        } catch (\InvalidArgumentException $e) {
            return $this->validationErrorResponse(null, $e->getMessage());
        } catch (\Exception $e) {
            Log::error('SmartBudget: Error adding category: '.$e->getMessage());
            return $this->errorResponse('Error adding category: ' . $e->getMessage());
        }
    }

    /**
     * Procesa texto de voz para extraer categoría y monto.
     */
    public function processVoiceCommand(Request $request): JsonResponse
    {
        $text = $request->input('text');

        if (! $text) {
            return $this->validationErrorResponse(null, 'Texto vacío');
        }

        try {
            $data = $this->aiService->interpretVoiceCommand($text);
            return $this->successResponse($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Error procesando voz: ' . $e->getMessage());
        }
    }

    // Los demás métodos (updateCategory, deleteCategory, validateBudget) siguen la misma lógica
    // Asegúrate de agregar Log::error en sus catch blocks.
}
