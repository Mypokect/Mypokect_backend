<?php

namespace App\Http\Controllers\Budget;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\Movement;
use App\Models\Tag;
use App\Services\BudgetAIService;
use App\Services\BudgetService;
use App\Services\MovementAIService;
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

    private MovementAIService $movementAiService;

    public function __construct(BudgetAIService $aiService, BudgetService $budgetService, MovementAIService $movementAiService)
    {
        $this->aiService = $aiService;
        $this->budgetService = $budgetService;
        $this->movementAiService = $movementAiService;
    }

    /**
     * List all budgets.
     *
     * Auto-archives expired budgets and returns all with spent amounts per category.
     */
    public function getBudgets(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            Log::info("User {$user->id} is requesting budgets list.");

            // ── Auto-archivar presupuestos vencidos ─────────────────────────────
            // Si date_to ya pasó y el presupuesto NO está archivado → archivarlo
            Budget::where('user_id', $user->id)
                ->whereNotNull('date_to')
                ->where('date_to', '<', now()->toDateString())
                ->whereIn('status', ['draft', 'active'])
                ->update(['status' => 'archived']);

            $budgets = Budget::where('user_id', $user->id)
                ->with('categories')
                ->orderBy('created_at', 'desc')
                ->get();

            // ── Calcular gasto actual para cada presupuesto ─────────────────────
            $tz = 'America/Bogota';
            $budgetsWithSpent = $budgets->map(function ($budget) use ($user, $tz) {
                $totalSpent = 0;

                if ($budget->date_from && $budget->date_to) {
                    // Apply Colombia timezone so movements at night aren't misclassified
                    $from = \Carbon\Carbon::createFromFormat('Y-m-d', $budget->date_from->format('Y-m-d'), $tz)
                        ->startOfDay()->utc()->toDateTimeString();
                    $to   = \Carbon\Carbon::createFromFormat('Y-m-d', $budget->date_to->format('Y-m-d'), $tz)
                        ->endOfDay()->utc()->toDateTimeString();

                    $linkedTags = [];
                    foreach ($budget->categories as $category) {
                        foreach ($this->extractTagNames($category->linked_tags) as $tag) {
                            if (! in_array($tag, $linkedTags)) {
                                $linkedTags[] = $tag;
                            }
                        }
                    }

                    if (! empty($linkedTags)) {
                        $totalSpent = Movement::where('user_id', $user->id)
                            ->where('type', 'expense')
                            ->whereBetween('created_at', [$from, $to])
                            ->whereHas('tag', function ($q) use ($linkedTags) {
                                $q->whereIn('name', $linkedTags);
                            })
                            ->sum('amount');
                    }
                }

                $budgetArray  = $budget->toArray();
                $totalAmount  = (float) ($budget->total_amount ?? 0);
                $montoGastado = round((float) $totalSpent, 2);
                $porcConsumido = $totalAmount > 0
                    ? min(1.0, round($montoGastado / $totalAmount, 4))
                    : 0.0;

                $budgetArray['spent']               = $montoGastado; // backward compat
                $budgetArray['monto_gastado']        = $montoGastado;
                $budgetArray['porcentaje_consumido'] = $porcConsumido;

                return $budgetArray;
            });

            Log::info('Found '.$budgets->count().' budgets with spending calculated.');

            return $this->successResponse($budgetsWithSpent);

        } catch (\Exception $e) {
            Log::error('Error fetching budgets: '.$e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Reactivate an archived budget.
     *
     * Sets status back to active and extends date_to.
     *
     * @bodyParam date_to string required New end date (>= today). Example: 2026-04-01
     */
    public function reactivateBudget(Request $request, Budget $budget): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            if ($budget->status !== 'archived') {
                return $this->validationErrorResponse(null, 'Este presupuesto no está archivado.');
            }

            $validated = Validator::make($request->all(), [
                'date_to' => 'required|date|after_or_equal:today',
            ]);

            if ($validated->fails()) {
                return $this->validationErrorResponse($validated->errors());
            }

            // Reactivar presupuesto (múltiples activos permitidos)
            $budget->update([
                'status' => 'active',
                'date_to' => $request->input('date_to'),
                // Invalidar caché de sugerencias IA al reactivar
                'suggested_tags_hash' => null,
                'suggested_tags_cache' => null,
            ]);

            $budget->load('categories');

            Log::info("Budget {$budget->id} reactivated until {$budget->date_to}");

            return $this->successResponse($budget, 'Presupuesto reactivado correctamente.');

        } catch (\Exception $e) {
            Log::error('Error reactivating budget: '.$e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Duplicate a budget.
     *
     * Creates a copy with the same categories, zero spending, and status active for the current period.
     */
    public function duplicateBudget(Budget $budget): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            return DB::transaction(function () use ($budget) {
                $dates = $budget->calculateCurrentPeriodDates();

                $newBudget = Budget::create([
                    'user_id' => $budget->user_id,
                    'title' => $budget->title,
                    'description' => $budget->description,
                    'total_amount' => $budget->total_amount,
                    'mode' => $budget->mode,
                    'language' => $budget->language,
                    'plan_type' => $budget->plan_type,
                    'status' => 'active',
                    'period' => $budget->period,
                    'date_from' => $dates['from'],
                    'date_to' => $dates['to'],
                ]);

                foreach ($budget->categories as $cat) {
                    BudgetCategory::create([
                        'budget_id' => $newBudget->id,
                        'name' => $cat->name,
                        'amount' => $cat->amount,
                        'percentage' => $cat->percentage,
                        'reason' => $cat->reason,
                        'linked_tags' => $cat->getRawOriginal('linked_tags'),
                        'order' => $cat->order,
                    ]);
                }

                $newBudget->load('categories');

                Log::info("Budget {$budget->id} duplicated as {$newBudget->id}");

                return $this->successResponse($newBudget, 'Presupuesto duplicado correctamente.');
            });

        } catch (\Exception $e) {
            Log::error("Error duplicating budget {$budget->id}: ".$e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Get a single budget.
     *
     * Returns the budget with its categories enriched with real execution data
     * (monto_gastado, monto_restante, porcentaje_progreso, estado_color).
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

            $enrichedCategories = $this->enrichCategoriesWithExecution($budget);

            $budgetData = $budget->toArray();
            $budgetData['categories'] = $enrichedCategories;

            return $this->successResponse([
                'budget' => $budgetData,
                'is_valid' => $budget->isValid(),
                'categories_total' => $budget->getCategoriesTotal(),
            ]);
        } catch (\Exception $e) {
            Log::error('SmartBudget: Error fetching single budget: '.$e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Create a manual budget.
     *
     * Creates a budget with user-defined categories. Sum of category amounts must equal total_amount.
     *
     * @bodyParam title string required Budget name. Example: Viaje a Cartagena
     * @bodyParam total_amount number required Total budget amount. Example: 500000
     * @bodyParam description string optional Budget description. Example: Viaje de fin de semana
     * @bodyParam date_from string optional Start date. Example: 2026-03-01
     * @bodyParam date_to string optional End date. Example: 2026-03-15
     * @bodyParam period string optional Period type: weekly, biweekly, monthly, custom. Example: biweekly
     * @bodyParam status string optional Budget status: active, pending, archived. Example: active
     * @bodyParam categories array required List of categories.
     * @bodyParam categories[].name string required Category name. Example: Hospedaje
     * @bodyParam categories[].amount number required Category amount. Example: 200000
     * @bodyParam categories[].reason string optional Category reason. Example: Hotel 3 noches
     */
    public function createManualBudget(Request $request): JsonResponse
    {
        Log::info('SmartBudget: Attempting to create MANUAL budget.');

        $validated = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'total_amount' => 'required|numeric|min:0.01',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'categories' => 'required|array|min:1',
            'categories.*.name' => 'required|string|max:255',
            'categories.*.amount' => 'required|numeric|min:0.01',
            'categories.*.reason' => 'nullable|string|max:500',
            'categories.*.linked_tags' => 'nullable', // Puede ser array simple o objeto con tags/keywords
            'categories.*.linked_keywords' => 'nullable|array',
            'status' => 'nullable|in:active,pending,archived', // Estado del presupuesto (default: active)
            'period' => 'nullable|string|in:weekly,biweekly,monthly,custom',
        ]);

        if ($validated->fails()) {
            Log::warning('SmartBudget: Validation failed for manual budget.', $validated->errors()->toArray());

            return $this->validationErrorResponse($validated->errors());
        }

        try {
            $user = Auth::user();

            $budget = $this->budgetService->createManualBudget($user, $request->only([
                'title', 'description', 'total_amount', 'date_from', 'date_to', 'categories', 'status', 'period',
            ]));

            return $this->createdResponse([
                'budget' => $budget,
                'is_valid' => $budget->isValid(),
            ], 'Budget created successfully');

        } catch (\InvalidArgumentException $e) {
            return $this->validationErrorResponse(null, $e->getMessage());
        } catch (\Exception $e) {
            Log::error('SmartBudget: Error creating manual budget: '.$e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Generate AI budget suggestions.
     *
     * Uses Groq AI to generate category distribution. Returns suggestions to review before saving.
     *
     * @bodyParam title string required Budget title. Example: Viaje a Medellín
     * @bodyParam description string required Context for AI. Example: Viaje de 5 días con mi pareja
     * @bodyParam total_amount number required Total amount. Example: 1000000
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

            $userTags = Tag::where('user_id', Auth::id())->pluck('name')->toArray();

            Log::info('SmartBudget: Calling AI Service...', ['user_tags_count' => count($userTags)]);
            $aiResult = $this->aiService->generateBudgetWithAI($title, $totalAmount, $description, $userTags);

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
                'suggested_period' => $budgetData['suggested_period'] ?? 'monthly',
                'duration_days' => $budgetData['duration_days'] ?? 30,
                'language' => $aiResult['language'],
                'plan_type' => $aiResult['plan_type'],
            ], 'AI suggestions generated. Review and save as budget.');

        } catch (\Exception $e) {
            Log::error('SmartBudget: Error in generateAIBudget: '.$e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Save an AI-generated budget.
     *
     * Persists the AI suggestions after user review and confirmation.
     *
     * @bodyParam title string required Budget title. Example: Viaje a Medellín
     * @bodyParam total_amount number required Total amount. Example: 1000000
     * @bodyParam description string optional Description. Example: Viaje de 5 días
     * @bodyParam date_from string optional Start date. Example: 2026-03-10
     * @bodyParam date_to string optional End date. Example: 2026-03-15
     * @bodyParam categories array required AI-generated categories.
     * @bodyParam categories[].name string required Category name. Example: Hospedaje
     * @bodyParam categories[].amount number required Category amount. Example: 400000
     * @bodyParam categories[].reason string optional Reason. Example: Hotel céntrico
     */
    public function saveAIBudget(Request $request): JsonResponse
    {
        Log::info('SmartBudget: Saving AI Generated Budget.', ['title' => $request->input('title')]);

        $validated = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'total_amount' => 'required|numeric|min:0.01',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'categories' => 'required|array|min:1',
            'categories.*.name' => 'required|string|max:255',
            'categories.*.amount' => 'required|numeric|min:0.01',
            'categories.*.reason' => 'nullable|string|max:500',
            'categories.*.linked_tags' => 'nullable', // Puede ser array simple o objeto con tags/keywords
            'categories.*.linked_keywords' => 'nullable|array',
            'language' => 'nullable|string|max:10',
            'plan_type' => 'nullable|string|in:travel,event,party,purchase,project,other',
            'status' => 'nullable|in:active,pending,archived', // Estado del presupuesto (default: active)
            'period' => 'nullable|string|in:weekly,biweekly,monthly,custom',
        ]);

        if ($validated->fails()) {
            Log::warning('SmartBudget: Save AI Validation failed.', $validated->errors()->toArray());

            return $this->validationErrorResponse($validated->errors());
        }

        try {
            $user = Auth::user();

            $budget = $this->budgetService->createAIBudget($user, $request->only([
                'title', 'description', 'total_amount', 'date_from', 'date_to', 'categories', 'language', 'plan_type', 'status', 'period',
            ]));

            return $this->createdResponse([
                'budget' => $budget,
                'is_valid' => $budget->isValid(),
            ], 'AI budget saved successfully');

        } catch (\InvalidArgumentException $e) {
            return $this->validationErrorResponse(null, $e->getMessage());
        } catch (\Exception $e) {
            Log::error('SmartBudget: Error saving AI budget: '.$e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Update a budget.
     *
     * Updates budget details and replaces categories. Invalidates AI suggestions cache.
     *
     * @bodyParam title string required Budget title. Example: Viaje actualizado
     * @bodyParam total_amount number required Total amount. Example: 600000
     * @bodyParam categories array required Updated categories list.
     * @bodyParam categories[].id int optional Existing category ID to update.
     * @bodyParam categories[].name string required Category name. Example: Transporte
     * @bodyParam categories[].amount number required Category amount. Example: 150000
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
                    'date_from' => 'nullable|date',
                    'date_to' => 'nullable|date|after_or_equal:date_from',
                    'categories' => 'required|array|min:1',
                    'categories.*.id' => 'nullable|integer',
                    'categories.*.name' => 'required|string|max:255',
                    'categories.*.amount' => 'required|numeric|min:0.01',
                    'categories.*.linked_tags' => 'nullable|array',
                    'categories.*.linked_tags.*' => 'string|max:255',
                ]);

                if ($validated->fails()) {
                    return $this->validationErrorResponse($validated->errors());
                }

                $budget = $this->budgetService->updateBudget($budget, $request->only([
                    'title', 'description', 'total_amount', 'date_from', 'date_to', 'categories', 'period',
                ]));

                // Invalidar caché de sugerencias IA: las categorías pueden haber cambiado,
                // lo que hace que el resultado anterior ya no sea relevante.
                // La próxima vez que se abra el presupuesto, el hash no coincidirá y
                // se volverá a llamar a Groq con el contexto actualizado.
                $budget->update([
                    'suggested_tags_hash' => null,
                    'suggested_tags_cache' => null,
                ]);

                Log::info('Budget updated: caché de sugerencias IA invalidado', [
                    'budget_id' => $budget->id,
                ]);

                return $this->successResponse($budget, 'Budget and categories updated successfully');

            } catch (\Exception $e) {
                Log::error('Error updating budget: '.$e->getMessage());
                throw $e; // Hace rollback de la transacción
            }
        });
    }

    /**
     * Delete a budget.
     *
     * Soft-deletes the budget and all its categories.
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

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Add a category to a budget.
     *
     * @bodyParam name string required Category name. Example: Entretenimiento
     * @bodyParam amount number required Category amount. Example: 100000
     * @bodyParam reason string optional Reason. Example: Actividades turísticas
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
                'name', 'amount', 'reason',
            ]));

            return $this->createdResponse($category, 'Category added');

        } catch (\InvalidArgumentException $e) {
            return $this->validationErrorResponse(null, $e->getMessage());
        } catch (\Exception $e) {
            Log::error('SmartBudget: Error adding category: '.$e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Process voice command for budget creation.
     *
     * Extracts category name and amount from natural language text using AI.
     *
     * @bodyParam text string required Voice transcription. Example: Hotel 200 mil
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
            Log::error('Error processing voice command: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Get spending per category.
     *
     * Calculates real spending per budget category based on linked tags and movement overrides.
     *
     * @queryParam from string optional Start date (defaults to budget's date_from). Example: 2026-03-01
     * @queryParam to string optional End date (defaults to budget's date_to). Example: 2026-03-15
     */
    public function getSpending(Request $request, Budget $budget): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            $validated = Validator::make($request->all(), [
                'from' => 'nullable|date',
                'to'   => 'nullable|date|after_or_equal:from',
            ]);
            if ($validated->fails()) {
                return $this->validationErrorResponse($validated->errors());
            }

            $from = $request->input('from', $budget->date_from?->format('Y-m-d'));
            $to   = $request->input('to', $budget->date_to?->format('Y-m-d'));
            if (! $from || ! $to) {
                return $this->validationErrorResponse(null, 'Date range required: provide from/to params or set date_from/date_to on the budget.');
            }

            $userId = Auth::id();
            $budget->load('categories');
            $categories = $budget->categories->values()->all();

            // ── PASO 1: Map tag → categorías + extraer keywords por categoría ──
            $tagToCatIndices = []; // tag => [idx, ...]
            $catKeywords     = []; // idx => [keyword, ...]
            $catNameWords    = []; // idx => [word, ...]

            foreach ($categories as $idx => $cat) {
                $linkedData = $cat->linked_tags ?? [];

                // Extraer keywords de IA (formato enriquecido)
                $keywords = [];
                if (is_array($linkedData) && isset($linkedData['keywords']) && is_array($linkedData['keywords'])) {
                    $keywords = array_values(array_filter(
                        array_map('mb_strtolower', $linkedData['keywords']),
                        fn ($k) => is_string($k) && mb_strlen($k) >= 2
                    ));
                }
                $catKeywords[$idx] = $keywords;

                // Palabras del nombre de categoría (fallback para matching)
                $catNameWords[$idx] = array_values(array_filter(
                    preg_split('/[\s\-_]+/', mb_strtolower($cat->name), -1, PREG_SPLIT_NO_EMPTY),
                    fn ($w) => mb_strlen($w) >= 3
                ));

                // Mapear tags
                foreach ($this->extractTagNames($linkedData) as $tag) {
                    $tagToCatIndices[$tag][] = $idx;
                }
            }

            // Deduplicar índices por tag
            foreach ($tagToCatIndices as $tag => $indices) {
                $tagToCatIndices[$tag] = array_values(array_unique($indices));
            }

            $allTags = array_keys($tagToCatIndices);
            $fromTs  = $from.' 00:00:00';
            $toTs    = $to.' 23:59:59';

            // ── PASO 2: Una sola query para todos los movimientos ──
            $totalSpent      = 0.0;
            $spentPerCat     = array_fill(0, count($categories), 0.0);
            $movementsPerCat = array_fill(0, count($categories), []);

            if (! empty($allTags)) {
                // ── Modo normal: buscar por linked_tags ──
                $movements = Movement::where('user_id', $userId)
                    ->where('type', 'expense')
                    ->whereBetween('created_at', [$fromTs, $toTs])
                    ->whereHas('tag', fn ($q) => $q->whereIn('name', $allTags))
                    ->with('tag:id,name')
                    ->get(['id', 'amount', 'tag_id', 'description', 'created_at']);

                // ── PASO 3: Asignar cada movimiento a UNA categoría ──
                foreach ($movements as $mov) {
                    $tagName = $mov->tag?->name;
                    $amount  = (float) $mov->amount;
                    $totalSpent += $amount;

                    if (! $tagName || ! isset($tagToCatIndices[$tagName])) {
                        continue;
                    }

                    $indices = $tagToCatIndices[$tagName];

                    if (count($indices) === 1) {
                        $bestIdx = $indices[0];
                    } else {
                        $bestIdx = $this->matchMovementToCategory(
                            $mov->description ?? '',
                            $tagName,
                            $indices,
                            $catKeywords,
                            $catNameWords
                        );
                    }

                    $spentPerCat[$bestIdx] += $amount;
                    $movementsPerCat[$bestIdx][] = [
                        'id'          => $mov->id,
                        'amount'      => $amount,
                        'description' => $mov->description,
                        'tag'         => $tagName,
                        'date'        => $mov->created_at->format('Y-m-d'),
                    ];
                }
            } else {
                // ── Fallback: sin linked_tags → matching por nombre de categoría ──
                // Buscar TODOS los gastos del periodo y hacer fuzzy matching
                // por nombre de tag/descripción contra nombres de categoría
                $movements = Movement::where('user_id', $userId)
                    ->where('type', 'expense')
                    ->whereBetween('created_at', [$fromTs, $toTs])
                    ->with('tag:id,name')
                    ->get(['id', 'amount', 'tag_id', 'description', 'created_at']);

                foreach ($movements as $mov) {
                    $tagName     = mb_strtolower($mov->tag?->name ?? '');
                    $description = mb_strtolower($mov->description ?? '');
                    $amount      = (float) $mov->amount;

                    // Intentar asignar a una categoría por similitud de nombre
                    $bestIdx   = -1;
                    $bestScore = 0;

                    foreach ($categories as $idx => $cat) {
                        $score = 0;
                        $nameWords = $catNameWords[$idx];

                        // Verificar si el tag o la descripción contienen palabras del nombre de categoría
                        foreach ($nameWords as $word) {
                            if (mb_strpos($tagName, $word) !== false) {
                                $score += 3;
                            }
                            if (mb_strpos($description, $word) !== false) {
                                $score += 2;
                            }
                        }

                        // Verificar si el nombre de categoría contiene el tag
                        $catNameLower = mb_strtolower($cat->name);
                        if ($tagName && mb_strpos($catNameLower, $tagName) !== false) {
                            $score += 4;
                        }
                        if ($tagName && mb_strpos($tagName, $catNameLower) !== false) {
                            $score += 4;
                        }

                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestIdx   = $idx;
                        }
                    }

                    if ($bestIdx >= 0 && $bestScore >= 2) {
                        $totalSpent += $amount;
                        $spentPerCat[$bestIdx] += $amount;
                        $movementsPerCat[$bestIdx][] = [
                            'id'          => $mov->id,
                            'amount'      => $amount,
                            'description' => $mov->description,
                            'tag'         => $mov->tag?->name,
                            'date'        => $mov->created_at->format('Y-m-d'),
                        ];
                    }
                }
            }

            // ── PASO 4: Aplicar movement_overrides (reasignaciones manuales) ──
            $overrides = $budget->movement_overrides ?? [];
            if (! empty($overrides)) {
                // Construir mapa nombre→índice de categoría
                $catNameToIdx = [];
                foreach ($categories as $idx => $cat) {
                    $catNameToIdx[$cat->name] = $idx;
                }

                foreach ($overrides as $movId => $targetCatName) {
                    $targetIdx = $catNameToIdx[$targetCatName] ?? null;
                    if ($targetIdx === null) continue;

                    $movIdInt = (int) $movId;

                    // Buscar y quitar el movimiento de su categoría actual
                    foreach ($movementsPerCat as $fromIdx => &$movsList) {
                        foreach ($movsList as $mKey => $m) {
                            if (($m['id'] ?? null) === $movIdInt && $fromIdx !== $targetIdx) {
                                $amount = (float) $m['amount'];
                                // Quitar de origen
                                $spentPerCat[$fromIdx] -= $amount;
                                unset($movsList[$mKey]);
                                $movsList = array_values($movsList);
                                // Agregar a destino
                                $spentPerCat[$targetIdx] += $amount;
                                $movementsPerCat[$targetIdx][] = $m;
                                break 2;
                            }
                        }
                    }
                    unset($movsList);
                }
            }

            // ── PASO 5: Construir respuesta ──
            $categoriesData = [];

            // Recalcular total_spent después de overrides
            $totalSpent = array_sum($spentPerCat);

            foreach ($categories as $idx => $cat) {
                $spent     = round($spentPerCat[$idx], 2);
                $budgeted  = (float) $cat->amount;
                $remaining = round($budgeted - $spent, 2);
                $progress  = $budgeted > 0 ? round(min($spent / $budgeted, 1.0), 4) : 0.0;

                $categoriesData[] = [
                    'name'                => $cat->name,
                    'budgeted'            => $budgeted,
                    'spent'               => $spent,
                    'remaining'           => $remaining,
                    'monto_gastado'       => $spent,
                    'monto_restante'      => $remaining,
                    'porcentaje_progreso' => $progress,
                    'estado_color'        => $this->estadoColor($spent, $budgeted),
                    'movements'           => $movementsPerCat[$idx],
                ];
            }

            return $this->successResponse([
                'budget_id'          => $budget->id,
                'total_budgeted'     => (float) $budget->total_amount,
                'total_spent'        => round($totalSpent, 2),
                'categories'         => $categoriesData,
            ]);

        } catch (\Exception $e) {
            Log::error('SmartBudget: Error fetching spending: '.$e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Validate a budget.
     *
     * Checks if the sum of all category amounts matches the budget's total_amount.
     */
    public function validateBudget(Budget $budget): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            $categoriesTotal = $budget->getCategoriesTotal();
            $isValid = $budget->isValid();
            $difference = round(abs($categoriesTotal - (float) $budget->total_amount), 2);

            return $this->successResponse([
                'is_valid' => $isValid,
                'categories_total' => $categoriesTotal,
                'total_amount' => (float) $budget->total_amount,
                'difference' => $difference,
                'message' => $isValid
                    ? 'Budget is valid'
                    : "Categories total ($categoriesTotal) differs from budget total ({$budget->total_amount})",
            ]);
        } catch (\Exception $e) {
            Log::error('Error validating budget: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Update a budget category.
     *
     * @bodyParam name string optional New category name. Example: Alimentación
     * @bodyParam amount number optional New amount. Example: 250000
     * @bodyParam reason string optional New reason. Example: Comidas y snacks
     */
    public function updateCategory(Request $request, Budget $budget, BudgetCategory $category): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            if ($category->budget_id !== $budget->id) {
                return $this->notFoundResponse('Category not found in this budget');
            }

            $validated = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'amount' => 'sometimes|numeric|min:0.01',
                'reason' => 'nullable|string|max:500',
            ]);

            if ($validated->fails()) {
                return $this->validationErrorResponse($validated->errors());
            }

            $category->update($request->only(['name', 'amount', 'reason']));

            return $this->successResponse($category);
        } catch (\Exception $e) {
            Log::error('Error updating category: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Delete a budget category.
     *
     * Removes a single category from the budget.
     */
    public function deleteCategory(Budget $budget, BudgetCategory $category): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            if ($category->budget_id !== $budget->id) {
                return $this->notFoundResponse('Category not found in this budget');
            }

            $category->delete();

            return $this->deletedResponse('Category deleted');
        } catch (\Exception $e) {
            Log::error('Error deleting category: ' . $e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Get AI-suggested tag assignments.
     *
     * Returns AI-suggested tag-to-category matches. Uses MD5-based cache to avoid redundant Groq calls.
     *
     * @queryParam from string optional Start date. Example: 2026-03-01
     * @queryParam to string optional End date. Example: 2026-03-15
     * @queryParam force_refresh boolean optional Force fresh AI analysis ignoring cache. Example: false
     */
    public function getSuggestedTags(Request $request, Budget $budget): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            $validated = Validator::make($request->all(), [
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
                'force_refresh' => 'nullable|boolean',
            ]);

            if ($validated->fails()) {
                return $this->validationErrorResponse($validated->errors());
            }

            // Usar fechas del presupuesto si no se especifican
            $from = $request->input('from', $budget->date_from?->format('Y-m-d'));
            $to = $request->input('to', $budget->date_to?->format('Y-m-d'));

            if (!$from || !$to) {
                return $this->validationErrorResponse(null, 'Date range required: provide from/to params or set date_from/date_to on the budget.');
            }

            $forceRefresh = $request->boolean('force_refresh', false);
            $userId = Auth::id();

            $budget->load('categories');

            // ── PASO 1: Obtener tags con gastos en el período ──────────────────────
            $tags = Tag::where('user_id', $userId)
                ->whereHas('movements', function ($q) use ($userId, $from, $to) {
                    $q->where('user_id', $userId)
                        ->where('type', 'expense')
                        ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
                })
                ->withSum(['movements as total_spent' => function ($q) use ($userId, $from, $to) {
                    $q->where('user_id', $userId)
                        ->where('type', 'expense')
                        ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
                }], 'amount')
                ->withCount(['movements as movement_count' => function ($q) use ($userId, $from, $to) {
                    $q->where('user_id', $userId)
                        ->where('type', 'expense')
                        ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
                }])
                ->orderByDesc('total_spent')
                ->get();

            if ($tags->isEmpty()) {
                return $this->successResponse([
                    'period_has_movements' => false,
                    'matches' => [],
                    'all_tags_with_amounts' => [],
                    'cached' => false,
                ]);
            }

            // ── PASO 2: Calcular hash determinístico ──────────────────────────────
            // Ordenamos por nombre antes de hashear para que el orden no importe
            $hashInput = $tags->sortBy('name')
                ->map(fn ($t) => "{$t->name}:{$t->total_spent}:{$t->movement_count}")
                ->implode('|');
            $hashInput .= "|period:{$from}:{$to}";
            $currentHash = md5($hashInput);

            // ── PASO 3: Verificar caché (hash HIT) ───────────────────────────────
            if (
                !$forceRefresh &&
                $budget->suggested_tags_hash === $currentHash &&
                $budget->suggested_tags_cache !== null
            ) {
                Log::info('Budget suggested tags: CACHE HIT (sin llamar a Groq)', [
                    'budget_id' => $budget->id,
                    'hash' => $currentHash,
                ]);

                return $this->successResponse(array_merge(
                    $budget->suggested_tags_cache,
                    ['period_has_movements' => true, 'cached' => true]
                ));
            }

            if ($forceRefresh) {
                Log::info('Budget suggested tags: FORCE REFRESH solicitado', [
                    'budget_id' => $budget->id,
                ]);
            }

            // ── PASO 4: Cache MISS → obtener MOVIMIENTOS individuales y llamar a Groq ─────
            Log::info('Budget suggested tags: CACHE MISS → llamando a Groq', [
                'budget_id' => $budget->id,
                'tags_count' => $tags->count(),
            ]);

            // NUEVO ENFOQUE: Obtener movimientos individuales, no tags agregados
            $movements = \App\Models\Movement::where('user_id', $userId)
                ->where('type', 'expense')
                ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->with('tag')
                ->get();

            // Preparar array de movimientos con tag + descripción + monto
            $movementsData = [];
            foreach ($movements as $movement) {
                $tagName = $movement->tag?->name ?? 'Sin etiqueta';
                $movementsData[] = [
                    'tag' => $tagName,
                    'description' => $movement->description ?? '',
                    'amount' => round((float) $movement->amount, 2),
                ];
            }

            Log::info('Movimientos individuales preparados', [
                'count' => count($movementsData),
            ]);

            $categoryNames = $budget->categories->pluck('name')->toArray();
            Log::info('Categorías del presupuesto a analizar', [
                'categories' => $categoryNames,
            ]);

            // Llamar al nuevo método que analiza movimientos individuales
            $matches = $this->movementAiService->matchMovementsToCategories(
                $categoryNames,
                $movementsData
            );

            // Agregar tags con sus totales para la UI
            $tagsWithSpending = $tags->map(fn ($tag) => [
                'name' => $tag->name,
                'total' => round((float) $tag->total_spent, 2),
            ])->toArray();

            // ── PASO 5: Preparar respuesta con doble formato ───────────────────────
            // $matches tiene formato: {"Hotel": {"tags": [...], "keywords": [...]}, ...}
            // Para compatibilidad con frontend, también crear versión simplificada

            $matchesSimple = []; // Solo tags, para el frontend
            $matchesDetailed = $matches; // Tags + keywords, para almacenar en BD

            foreach ($matches as $categoryName => $data) {
                // Extraer solo los tags para la versión simple
                $matchesSimple[$categoryName] = $data['tags'] ?? [];
            }

            // Respuesta para el frontend: usa versión simple
            $responsePayload = [
                'matches' => $matchesSimple, // Frontend espera solo arrays de tags
                'matches_detailed' => $matchesDetailed, // Información completa para guardar
                'all_tags_with_amounts' => $tagsWithSpending,
            ];

            // Guardar en caché con formato completo (detailed)
            $cachePayload = [
                'matches' => $matchesSimple,
                'matches_detailed' => $matchesDetailed,
                'all_tags_with_amounts' => $tagsWithSpending,
            ];

            $budget->update([
                'suggested_tags_cache' => $cachePayload,
                'suggested_tags_hash' => $currentHash,
            ]);

            Log::info('Budget suggested tags: resultado guardado en caché BD', [
                'budget_id' => $budget->id,
                'hash' => $currentHash,
                'simple_tags' => $matchesSimple,
                'with_keywords' => array_map(fn($d) => count($d['keywords'] ?? []), $matchesDetailed),
            ]);

            return $this->successResponse(array_merge(
                $responsePayload,
                ['period_has_movements' => true, 'cached' => false]
            ));

        } catch (\Exception $e) {
            Log::error('Error getting suggested tags: '.$e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Clear AI suggestions cache.
     *
     * Removes cached tag suggestions so the next request performs a fresh AI analysis.
     */
    public function clearSuggestedTagsCache(Request $request, Budget $budget): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            $budget->update([
                'suggested_tags_cache' => null,
                'suggested_tags_hash' => null,
            ]);

            Log::info('Budget suggested tags cache cleared', [
                'budget_id' => $budget->id,
                'user_id' => Auth::id(),
            ]);

            return $this->successResponse([
                'message' => 'Cache cleared successfully. Next request will perform fresh AI analysis.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error clearing suggested tags cache: '.$e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Apply AI-suggested tags to categories.
     *
     * Saves linked_tags to each category. Does not invalidate the AI suggestions cache.
     *
     * @bodyParam category_tags object required Map of category name to tag names array. Example: {"Hospedaje": ["Hotel"], "Comida": ["Restaurante"]}
     */
    public function applyAITags(Request $request, Budget $budget): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            $categoryTags = $request->input('category_tags', []);

            if (empty($categoryTags) || !is_array($categoryTags)) {
                return $this->validationErrorResponse(null, 'category_tags is required and must be an object');
            }

            $budget->load('categories');
            $updated = 0;

            foreach ($budget->categories as $category) {
                if (!array_key_exists($category->name, $categoryTags)) {
                    continue;
                }

                $tagNames = $categoryTags[$category->name];

                if (!is_array($tagNames)) {
                    continue;
                }

                // Si la lista está vacía, limpiar los linked_tags de esta categoría
                if (empty($tagNames)) {
                    $category->update([
                        'linked_tags' => [],
                        'linked_tags_since' => null,
                    ]);
                    $updated++;
                    continue;
                }

                // Enrich with keywords from AI cache
                $enriched = $this->budgetService->enrichLinkedTagsWithKeywords($budget, $category->name, $tagNames);

                $category->update([
                    'linked_tags' => $enriched,
                    'linked_tags_since' => null,
                ]);

                $updated++;
                Log::info("applyAITags: saved tags for '{$category->name}'", [
                    'budget_id' => $budget->id,
                    'tags' => $tagNames,
                    'enriched' => $enriched,
                ]);
            }

            return $this->successResponse([
                'updated' => $updated,
                'message' => "$updated categories updated with AI tags",
            ]);

        } catch (\Exception $e) {
            Log::error('Error applying AI tags: '.$e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Returns 'green' (<75%), 'yellow' (75-99%), 'red' (>=100%) based on spend ratio.
     */
    private function estadoColor(float $spent, float $budgeted): string
    {
        if ($budgeted <= 0) return 'green';
        $ratio = $spent / $budgeted;
        if ($ratio >= 1.0) return 'red';
        if ($ratio >= 0.75) return 'yellow';
        return 'green';
    }

    /**
     * Enriches budget categories with real execution data using linked_tags and budget dates.
     * Used by getBudget (show) so the detail view gets spending data in a single request.
     */
    private function enrichCategoriesWithExecution(Budget $budget): array
    {
        $categories = $budget->categories->values()->all();

        // No dates → return categories with zeroed execution fields
        if (! $budget->date_from) {
            return array_map(function ($cat) {
                $data = $cat->toArray();
                $data['monto_gastado']       = 0.0;
                $data['monto_restante']      = (float) $cat->amount;
                $data['porcentaje_progreso'] = 0.0;
                $data['estado_color']        = 'green';
                return $data;
            }, $categories);
        }

        $from   = $budget->date_from->format('Y-m-d').' 00:00:00';
        $to     = min($budget->date_to ?? now(), now())->format('Y-m-d').' 23:59:59';
        $userId = $budget->user_id;

        // Build tag → first category index map (simple, no keyword disambiguation)
        $tagToCatIdx = [];
        foreach ($categories as $idx => $cat) {
            foreach ($this->extractTagNames($cat->linked_tags ?? []) as $tag) {
                if (! isset($tagToCatIdx[$tag])) {
                    $tagToCatIdx[$tag] = $idx;
                }
            }
        }

        $spentPerCat = array_fill(0, count($categories), 0.0);

        if (! empty($tagToCatIdx)) {
            $movements = Movement::where('user_id', $userId)
                ->where('type', 'expense')
                ->whereBetween('created_at', [$from, $to])
                ->whereHas('tag', fn ($q) => $q->whereIn('name', array_keys($tagToCatIdx)))
                ->with('tag:id,name')
                ->get(['amount', 'tag_id']);

            foreach ($movements as $mov) {
                $tagName = $mov->tag?->name;
                if ($tagName && isset($tagToCatIdx[$tagName])) {
                    $spentPerCat[$tagToCatIdx[$tagName]] += (float) $mov->amount;
                }
            }
        }

        return array_map(function ($cat, $idx) use ($spentPerCat) {
            $spent     = round($spentPerCat[$idx], 2);
            $budgeted  = (float) $cat->amount;
            $remaining = round($budgeted - $spent, 2);
            $progress  = $budgeted > 0 ? round(min($spent / $budgeted, 1.0), 4) : 0.0;

            $data = $cat->toArray();
            $data['monto_gastado']       = $spent;
            $data['monto_restante']      = $remaining;
            $data['porcentaje_progreso'] = $progress;
            $data['estado_color']        = $this->estadoColor($spent, $budgeted);
            return $data;
        }, $categories, array_keys($categories));
    }

    /**
     * Extract tag names from a linked_tags column value.
     * Supports both formats: ["tag1", "tag2"] and {"tags": ["tag1"], "keywords": [...]}
     */
    private function extractTagNames($linkedData): array
    {
        if (! is_array($linkedData) || empty($linkedData)) {
            return [];
        }
        if (isset($linkedData['tags']) && is_array($linkedData['tags'])) {
            return array_filter($linkedData['tags'], 'is_string');
        }
        if (array_key_exists(0, $linkedData)) {
            return array_filter($linkedData, 'is_string');
        }

        return [];
    }

    /**
     * For shared tags: decide which category a movement belongs to
     * based on its description matching keywords (from AI) and category name words.
     * Falls back to tag name matching when description is empty/generic.
     * Returns the index of the best-matching category, or -1 if no match.
     */
    private function matchMovementToCategory(
        string $description,
        string $tagName,
        array $catIndices,
        array $catKeywords,
        array $catNameWords
    ): int {
        $descLower = mb_strtolower(trim($description));

        // Descripciones genéricas/por defecto → tratarlas como vacías
        $genericDescriptions = ['movimiento', 'sin descripción', 'sin descripcion', ''];
        $hasUsefulDescription = ! in_array($descLower, $genericDescriptions, true);

        // Texto a comparar: descripción útil, o el nombre del tag como fallback
        $matchText = $hasUsefulDescription ? $descLower : mb_strtolower(trim($tagName));

        if (empty($matchText)) {
            return -1;
        }

        $bestIdx   = $catIndices[0]; // Fallback: primera categoría (evita spending leak)
        $bestScore = 0;

        foreach ($catIndices as $idx) {
            $score = 0;
            $hasAIKeywords = ! empty($catKeywords[$idx]);

            // Keywords de IA (2 puntos cada match — más preciso)
            foreach ($catKeywords[$idx] as $keyword) {
                if (mb_strpos($matchText, $keyword) !== false) {
                    $score += 2;
                }
            }

            // Palabras del nombre de categoría
            // Si no hay keywords de IA → 2 puntos (compensar ausencia de IA)
            // Si hay keywords de IA → 1 punto (fallback secundario)
            $nameWeight = $hasAIKeywords ? 1 : 2;
            foreach ($catNameWords[$idx] as $word) {
                if (mb_strpos($matchText, $word) !== false) {
                    $score += $nameWeight;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdx   = $idx;
            }
        }

        return $bestIdx;
    }

    /**
     * Move a movement to a different category.
     *
     * Stores a per-movement override so the movement appears under the target category in spending reports.
     *
     * @bodyParam movement_id int required The movement ID to reassign. Example: 42
     * @bodyParam to_category string required Target category name. Example: Transporte
     */
    public function moveMovement(Request $request, Budget $budget): JsonResponse
    {
        try {
            if ($budget->user_id !== Auth::id()) {
                return $this->unauthorizedResponse();
            }

            $validated = Validator::make($request->all(), [
                'movement_id' => 'required|integer',
                'to_category' => 'required|string',
            ]);
            if ($validated->fails()) {
                return $this->validationErrorResponse($validated->errors());
            }

            $movementId = (string) $request->input('movement_id');
            $toCategory = $request->input('to_category');

            // Verificar que la categoría destino existe
            $budget->load('categories');
            $catExists = $budget->categories->contains(fn ($c) => $c->name === $toCategory);
            if (! $catExists) {
                return $this->validationErrorResponse(null, "Category '$toCategory' not found in this budget.");
            }

            // Leer overrides actuales y agregar/actualizar
            $overrides = $budget->movement_overrides ?? [];
            $overrides[$movementId] = $toCategory;

            $budget->update(['movement_overrides' => $overrides]);

            return $this->successResponse([
                'message' => 'Movement override saved',
                'movement_id' => $movementId,
                'to_category' => $toCategory,
                'total_overrides' => count($overrides),
            ]);
        } catch (\Exception $e) {
            Log::error('Error moving movement: '.$e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }
}
