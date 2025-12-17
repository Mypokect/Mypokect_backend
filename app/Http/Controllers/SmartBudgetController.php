<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Services\BudgetAIService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SmartBudgetController extends Controller
{
    private BudgetAIService $aiService;

    public function __construct(BudgetAIService $aiService)
    {
        $this->aiService = $aiService;
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

            Log::info("Found " . $budgets->count() . " budgets.");

            return response()->json([
                'success' => true,
                'data' => $budgets 
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching budgets: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error fetching budgets',
                'message' => $e->getMessage()
            ], 500);
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
                Log::warning("SmartBudget: Unauthorized access attempt to budget {$budget->id} by user " . Auth::id());
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $budget->load('categories');

            return response()->json([
                'success' => true,
                'data' => $budget,
                'is_valid' => $budget->isValid(),
                'categories_total' => $budget->getCategoriesTotal()
            ], 200);
        } catch (\Exception $e) {
            Log::error('SmartBudget: Error fetching single budget: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error fetching budget',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a manual budget (MODO 1: MANUAL).
     */
    public function createManualBudget(Request $request): JsonResponse
    {
        Log::info("SmartBudget: Attempting to create MANUAL budget.", $request->all());

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
            Log::warning("SmartBudget: Validation failed for manual budget.", $validated->errors()->toArray());
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validated->errors()
            ], 422);
        }

        try {
            $budget = DB::transaction(function () use ($request) {
                $user = Auth::user();
                $totalAmount = (float)$request->input('total_amount');
                $categories = $request->input('categories');

                $categoriesSum = array_sum(array_column($categories, 'amount'));
                
                Log::info("SmartBudget: Math Check - Total: $totalAmount, Sum: $categoriesSum");

                if (abs($categoriesSum - $totalAmount) > 0.01) {
                    Log::error("SmartBudget: Math mismatch. Sum ($categoriesSum) != Total ($totalAmount)");
                    throw new \InvalidArgumentException("Sum of categories (\$$categoriesSum) does not match total amount (\$$totalAmount)");
                }

                $budget = Budget::create([
                    'user_id' => $user->id,
                    'title' => $request->input('title'),
                    'description' => $request->input('description'),
                    'total_amount' => $totalAmount,
                    'mode' => 'manual',
                    'language' => $this->aiService->detectLanguage(
                        $request->input('title') . ' ' . $request->input('description')
                    ),
                    'plan_type' => $this->aiService->classifyPlanType($request->input('description')),
                    'status' => 'draft'
                ]);

                Log::info("SmartBudget: Budget created with ID: {$budget->id}");

                foreach ($categories as $index => $category) {
                    $amount = (float)$category['amount'];
                    BudgetCategory::create([
                        'budget_id' => $budget->id,
                        'name' => $category['name'],
                        'amount' => $amount,
                        'percentage' => round(($amount / $totalAmount) * 100, 2),
                        'reason' => $category['reason'] ?? '',
                        'order' => $index
                    ]);
                }

                $budget->load('categories');
                Log::info("SmartBudget: Categories attached successfully.");
                return $budget;
            });

            return response()->json([
                'success' => true,
                'message' => 'Budget created successfully',
                'data' => $budget,
                'is_valid' => $budget->isValid()
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Invalid budget',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('SmartBudget: Critical Error creating manual budget: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error creating budget',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate budget with AI suggestions (MODO 2: IA).
     */
    public function generateAIBudget(Request $request): JsonResponse
    {
        Log::info("SmartBudget: Starting AI Generation request.", $request->only(['title', 'total_amount']));

        try {
            $validated = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'description' => 'required|string|max:2000',
                'total_amount' => 'required|numeric|min:0.01',
            ]);

            if ($validated->fails()) {
                Log::warning("SmartBudget: AI Input Validation failed.", $validated->errors()->toArray());
                return response()->json([
                    'error' => 'Validation failed',
                    'messages' => $validated->errors()
                ], 422);
            }

            $title = $request->input('title');
            $description = $request->input('description');
            $totalAmount = (float)$request->input('total_amount');

            Log::info("SmartBudget: Calling AI Service...");
            $aiResult = $this->aiService->generateBudgetWithAI($title, $totalAmount, $description);

            if (!$aiResult['success']) {
                Log::error("SmartBudget: AI Service failed to return success.");
                throw new \Exception('Failed to generate AI suggestions');
            }

            Log::info("SmartBudget: AI Service returned success. Parsing data.");
            $budgetData = $aiResult['data'];

            return response()->json([
                'success' => true,
                'message' => 'AI suggestions generated. Review and save as budget.',
                'data' => [
                    'title' => $title,
                    'description' => $description,
                    'total_amount' => $totalAmount,
                    'categories' => $budgetData['categories'] ?? [],
                    'general_advice' => $budgetData['general_advice'] ?? '',
                    'language' => $aiResult['language'],
                    'plan_type' => $aiResult['plan_type'],
                ],
                'note' => 'These are AI suggestions. You can edit, add, or remove categories before saving.'
            ], 200);

        } catch (\Exception $e) {
            Log::error('SmartBudget: Error in generateAIBudget: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error generating AI suggestions',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save an AI-generated budget (user confirms after reviewing).
     */
    public function saveAIBudget(Request $request): JsonResponse
    {
        Log::info("SmartBudget: Saving AI Generated Budget.", ['title' => $request->input('title')]);

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
            Log::warning("SmartBudget: Save AI Validation failed.", $validated->errors()->toArray());
            return response()->json(['error' => 'Validation failed', 'messages' => $validated->errors()], 422);
        }

        try {
            $budget = DB::transaction(function () use ($request) {
                $user = Auth::user();
                $totalAmount = (float)$request->input('total_amount');
                $categories = $request->input('categories');

                $categoriesSum = array_sum(array_column($categories, 'amount'));
                if (abs($categoriesSum - $totalAmount) > 0.01) {
                    Log::error("SmartBudget: Save AI Math mismatch. Sum: $categoriesSum, Total: $totalAmount");
                    throw new \InvalidArgumentException("Sum of categories does not match total amount");
                }

                $budget = Budget::create([
                    'user_id' => $user->id,
                    'title' => $request->input('title'),
                    'description' => $request->input('description'),
                    'total_amount' => $totalAmount,
                    'mode' => 'ai',
                    'language' => $request->input('language', 'es'),
                    'plan_type' => $request->input('plan_type', 'other'),
                    'status' => 'draft'
                ]);

                Log::info("SmartBudget: AI Budget saved ID: {$budget->id}");

                foreach ($categories as $index => $category) {
                    $amount = (float)$category['amount'];
                    BudgetCategory::create([
                        'budget_id' => $budget->id,
                        'name' => $category['name'],
                        'amount' => $amount,
                        'percentage' => round(($amount / $totalAmount) * 100, 2),
                        'reason' => $category['reason'] ?? '',
                        'order' => $index
                    ]);
                }

                $budget->load('categories');
                return $budget;
            });

            return response()->json([
                'success' => true,
                'message' => 'AI budget saved successfully',
                'data' => $budget,
                'is_valid' => $budget->isValid()
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Invalid budget', 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('SmartBudget: Error saving AI budget: ' . $e->getMessage());
            return response()->json(['error' => 'Error saving budget', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update a budget.
     */
    public function updateBudget(Request $request, Budget $budget): JsonResponse
    {
        // Usamos una transacción para que todo se guarde o nada se guarde (seguridad de datos)
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $budget) {
            try {
                if ($budget->user_id !== Auth::id()) {
                    return response()->json(['error' => 'Unauthorized'], 403);
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
                    return response()->json(['error' => 'Validation failed', 'messages' => $validated->errors()], 422);
                }

                $totalAmount = (float)$request->input('total_amount');
                $categoriesInput = $request->input('categories');

                // 1. Validar suma matemática
                $categoriesSum = array_sum(array_column($categoriesInput, 'amount'));
                if (abs($categoriesSum - $totalAmount) > 0.1) { // Margen de 0.1
                    return response()->json([
                        'error' => 'Math Mismatch',
                        'message' => "La suma de categorías ($categoriesSum) no coincide con el total ($totalAmount)."
                    ], 422);
                }

                // 2. Actualizar el Presupuesto Padre
                $budget->update([
                    'title' => $request->input('title'),
                    'description' => $request->input('description'),
                    'total_amount' => $totalAmount,
                ]);

                // 3. Sincronizar Categorías
                
                // Obtener IDs que vienen del frontend (los que no tengan ID son nuevos)
                $incomingIds = array_filter(array_column($categoriesInput, 'id'));

                // A. Eliminar categorías que ya no están en la lista
                $budget->categories()->whereNotIn('id', $incomingIds)->delete();

                // B. Actualizar o Crear
                foreach ($categoriesInput as $index => $catData) {
                    $amount = (float)$catData['amount'];
                    $pct = round(($amount / $totalAmount) * 100, 2);

                    if (isset($catData['id']) && $catData['id']) {
                        // Actualizar existente
                        $category = BudgetCategory::find($catData['id']);
                        if ($category && $category->budget_id == $budget->id) {
                            $category->update([
                                'name' => $catData['name'],
                                'amount' => $amount,
                                'percentage' => $pct,
                                'reason' => $catData['reason'] ?? '',
                                'order' => $index
                            ]);
                        }
                    } else {
                        // Crear nueva
                        BudgetCategory::create([
                            'budget_id' => $budget->id,
                            'name' => $catData['name'],
                            'amount' => $amount,
                            'percentage' => $pct,
                            'reason' => $catData['reason'] ?? '',
                            'order' => $index
                        ]);
                    }
                }

                $budget->load('categories');

                return response()->json([
                    'success' => true,
                    'message' => 'Budget and categories updated successfully',
                    'data' => $budget
                ], 200);

            } catch (\Exception $e) {
                Log::error('Error updating budget: ' . $e->getMessage());
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
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            Log::info("SmartBudget: Deleting budget {$budget->id}");
            $budget->categories()->delete();
            $budget->delete();

            return response()->json([
                'success' => true,
                'message' => 'Budget deleted'
            ], 200);

        } catch (\Exception $e) {
            Log::error('SmartBudget: Error deleting budget: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error deleting budget',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add a category to an existing budget.
     */
    public function addCategory(Request $request, Budget $budget): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $validated = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0.01',
                'reason' => 'nullable|string|max:500',
            ]);

            if ($validated->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'messages' => $validated->errors()
                ], 422);
            }

            $amount = (float)$request->input('amount');
            $currentSum = $budget->getCategoriesTotal();

            Log::info("SmartBudget: Adding category. Current: $currentSum, New: $amount, Max: {$budget->total_amount}");

            if ($currentSum + $amount > $budget->total_amount) {
                return response()->json([
                    'error' => 'Amount exceeds budget',
                    'message' => "Adding \$$amount would exceed total budget. Current sum: \$$currentSum, Total: \$" . $budget->total_amount
                ], 422);
            }

            $category = BudgetCategory::create([
                'budget_id' => $budget->id,
                'name' => $request->input('name'),
                'amount' => $amount,
                'percentage' => round(($amount / $budget->total_amount) * 100, 2),
                'reason' => $request->input('reason', ''),
                'order' => $budget->categories()->count()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Category added',
                'data' => $category
            ], 201);

        } catch (\Exception $e) {
            Log::error('SmartBudget: Error adding category: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error adding category',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Procesa texto de voz para extraer categoría y monto.
     */
    public function processVoiceCommand(Request $request): JsonResponse
    {
        $text = $request->input('text');
        
        if (!$text) {
            return response()->json(['error' => 'Texto vacío'], 400);
        }

        try {
            $data = $this->aiService->interpretVoiceCommand($text);
            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error procesando voz'], 500);
        }
    }

    // Los demás métodos (updateCategory, deleteCategory, validateBudget) siguen la misma lógica
    // Asegúrate de agregar Log::error en sus catch blocks.
}