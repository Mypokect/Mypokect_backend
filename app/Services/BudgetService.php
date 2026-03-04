<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BudgetService
{
    private BudgetAIService $aiService;

    public function __construct(BudgetAIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Create a manual budget with categories.
     *
     * Multiple active budgets are allowed. The budget analysis uses the date_from/date_to
     * period to determine which movements to include.
     *
     * @param  User  $user
     * @param  array  $data  ['status' => 'active|pending|archived', 'date_from' => date, 'date_to' => date, ...]
     * @throws \InvalidArgumentException
     */
    public function createManualBudget(User $user, array $data): Budget
    {
        return DB::transaction(function () use ($user, $data) {
            $totalAmount = (float) $data['total_amount'];
            $categories = $data['categories'];

            // Validate sum (tolerance: 1.0 — matches frontend's isBalanced check)
            $categoriesSum = array_sum(array_column($categories, 'amount'));
            Log::info("BudgetService: Math Check - Total: $totalAmount, Sum: $categoriesSum");

            if (abs($categoriesSum - $totalAmount) > 1.0) {
                Log::error("BudgetService: Math mismatch. Sum ($categoriesSum) != Total ($totalAmount)");
                throw new \InvalidArgumentException("Sum of categories (\$$categoriesSum) does not match total amount (\$$totalAmount)");
            }

            // Determine status (default: active for backwards compatibility)
            $status = $data['status'] ?? 'active';

            // Create budget (múltiples presupuestos activos permitidos - la lógica de periodo maneja cuál usar)
            $budget = Budget::create([
                'user_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'total_amount' => $totalAmount,
                'mode' => 'manual',
                'language' => $this->aiService->detectLanguage(
                    $data['title'].' '.($data['description'] ?? '')
                ),
                'plan_type' => $this->aiService->classifyPlanType($data['description'] ?? ''),
                'status' => $status,
                'period' => $data['period'] ?? 'monthly',
                'date_from' => $data['date_from'] ?? null,
                'date_to' => $data['date_to'] ?? null,
            ]);

            Log::info("BudgetService: Budget created with ID: {$budget->id}");

            // Create categories
            $this->createCategories($budget, $categories, $totalAmount);

            $budget->load('categories');
            Log::info('BudgetService: Categories attached successfully.');

            return $budget;
        });
    }

    /**
     * Create an AI-generated budget.
     *
     * Multiple active budgets are allowed. The budget analysis uses the date_from/date_to
     * period to determine which movements to include.
     *
     * @param  User  $user
     * @param  array  $data  ['status' => 'active|pending|archived', 'date_from' => date, 'date_to' => date, ...]
     * @throws \InvalidArgumentException
     */
    public function createAIBudget(User $user, array $data): Budget
    {
        return DB::transaction(function () use ($user, $data) {
            $totalAmount = (float) $data['total_amount'];
            $categories = $data['categories'];

            // Validate sum (tolerance: 1.0 — matches frontend's isBalanced check)
            $categoriesSum = array_sum(array_column($categories, 'amount'));
            if (abs($categoriesSum - $totalAmount) > 1.0) {
                Log::error("BudgetService: Save AI Math mismatch. Sum: $categoriesSum, Total: $totalAmount");
                throw new \InvalidArgumentException('Sum of categories does not match total amount');
            }

            // Determine status (default: active for backwards compatibility)
            $status = $data['status'] ?? 'active';

            // Create budget (múltiples presupuestos activos permitidos - la lógica de periodo maneja cuál usar)
            $budget = Budget::create([
                'user_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'total_amount' => $totalAmount,
                'mode' => 'ai',
                'language' => $data['language'] ?? 'es',
                'plan_type' => $data['plan_type'] ?? 'other',
                'status' => $status,
                'period' => $data['period'] ?? 'monthly',
                'date_from' => $data['date_from'] ?? null,
                'date_to' => $data['date_to'] ?? null,
            ]);

            Log::info("BudgetService: AI Budget saved ID: {$budget->id}");

            // Create categories
            $this->createCategories($budget, $categories, $totalAmount);

            $budget->load('categories');

            return $budget;
        });
    }

    /**
     * Update budget and its categories.
     *
     * @throws \InvalidArgumentException
     */
    public function updateBudget(Budget $budget, array $data): Budget
    {
        $totalAmount = (float) $data['total_amount'];
        $categoriesInput = $data['categories'];

        // Validate sum (tolerance: 1.0 — matches frontend's isBalanced check)
        $categoriesSum = array_sum(array_column($categoriesInput, 'amount'));
        if (abs($categoriesSum - $totalAmount) > 1.0) {
            throw new \InvalidArgumentException("La suma de categorías ($categoriesSum) no coincide con el total ($totalAmount).");
        }

        // Update budget
        $budget->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'total_amount' => $totalAmount,
            'period' => $data['period'] ?? $budget->period,
            'date_from' => $data['date_from'] ?? $budget->date_from,
            'date_to' => $data['date_to'] ?? $budget->date_to,
        ]);

        // Sync categories
        $this->syncCategories($budget, $categoriesInput, $totalAmount);

        $budget->load('categories');

        return $budget;
    }

    /**
     * Add a category to an existing budget.
     *
     * @throws \InvalidArgumentException
     */
    public function addCategory(Budget $budget, array $data): BudgetCategory
    {
        $amount = (float) $data['amount'];
        $currentSum = $budget->getCategoriesTotal();

        Log::info("BudgetService: Adding category. Current: $currentSum, New: $amount, Max: {$budget->total_amount}");

        if ($currentSum + $amount > $budget->total_amount) {
            throw new \InvalidArgumentException("Adding \$$amount would exceed total budget. Current sum: \$$currentSum, Total: \$".$budget->total_amount);
        }

        $linkedTags = $data['linked_tags'] ?? [];
        $enrichedLinkedTags = $this->enrichLinkedTagsWithKeywords($budget, $data['name'], $linkedTags);

        $category = BudgetCategory::create([
            'budget_id' => $budget->id,
            'name' => $data['name'],
            'amount' => $amount,
            'percentage' => round(($amount / $budget->total_amount) * 100, 2),
            'reason' => $data['reason'] ?? '',
            'linked_tags' => $enrichedLinkedTags,
            'linked_tags_since' => null, // Ya no restringimos por timestamp
            'order' => $budget->categories()->count(),
        ]);

        return $category;
    }

    /**
     * Delete budget and all its categories.
     */
    public function deleteBudget(Budget $budget): void
    {
        Log::info("BudgetService: Deleting budget {$budget->id}");
        $budget->categories()->delete();
        $budget->delete();
    }

    /**
     * Create categories for a budget.
     */
    private function createCategories(Budget $budget, array $categories, float $totalAmount): void
    {
        foreach ($categories as $index => $category) {
            $amount = (float) $category['amount'];
            $linkedTags = $category['linked_tags'] ?? [];
            $enrichedLinkedTags = $this->enrichLinkedTagsWithKeywords($budget, $category['name'], $linkedTags);

            BudgetCategory::create([
                'budget_id' => $budget->id,
                'name' => $category['name'],
                'amount' => $amount,
                'percentage' => round(($amount / $totalAmount) * 100, 2),
                'reason' => $category['reason'] ?? '',
                'linked_tags' => $enrichedLinkedTags,
                'linked_tags_since' => null, // Ya no restringimos por timestamp
                'order' => $index,
            ]);
        }
    }

    /**
     * Sync categories with budget (update, delete, create).
     */
    private function syncCategories(Budget $budget, array $categoriesInput, float $totalAmount): void
    {
        // Get incoming IDs
        $incomingIds = array_filter(array_column($categoriesInput, 'id'));

        // Delete categories not in the list
        $budget->categories()->whereNotIn('id', $incomingIds)->delete();

        // Update or create categories
        foreach ($categoriesInput as $index => $catData) {
            $amount = (float) $catData['amount'];
            $pct = round(($amount / $totalAmount) * 100, 2);

            $newLinkedTags = $catData['linked_tags'] ?? [];
            $enrichedLinkedTags = $this->enrichLinkedTagsWithKeywords($budget, $catData['name'], $newLinkedTags);

            if (isset($catData['id']) && $catData['id']) {
                // Update existing
                $category = BudgetCategory::find($catData['id']);
                if ($category && $category->budget_id == $budget->id) {
                    $category->update([
                        'name' => $catData['name'],
                        'amount' => $amount,
                        'percentage' => $pct,
                        'reason' => $catData['reason'] ?? '',
                        'linked_tags' => $enrichedLinkedTags,
                        'linked_tags_since' => null, // Ya no restringimos por timestamp
                        'order' => $index,
                    ]);
                }
            } else {
                // Create new
                BudgetCategory::create([
                    'budget_id' => $budget->id,
                    'name' => $catData['name'],
                    'amount' => $amount,
                    'percentage' => $pct,
                    'reason' => $catData['reason'] ?? '',
                    'linked_tags' => $enrichedLinkedTags,
                    'linked_tags_since' => null, // Ya no restringimos por timestamp
                    'order' => $index,
                ]);
            }
        }
    }

    /**
     * Enrich linked_tags with keywords from cached AI suggestions.
     * Converts simple array ["Servicio"] to rich format {"tags": ["Servicio"], "keywords": ["hotel", ...]}.
     *
     * @param  Budget  $budget
     * @param  string  $categoryName
     * @param  array|mixed  $linkedTags  Simple array of tag names from frontend
     * @return array|mixed  Rich format with tags and keywords if AI data available, otherwise original
     */
    public function enrichLinkedTagsWithKeywords(Budget $budget, string $categoryName, $linkedTags)
    {
        // If linked_tags is empty or not an array, return as-is
        if (empty($linkedTags) || !is_array($linkedTags)) {
            return $linkedTags;
        }

        // If already in rich format (has 'tags' and 'keywords' keys), return as-is
        if (isset($linkedTags['tags']) && is_array($linkedTags['tags'])) {
            return $linkedTags;
        }

        // Check if budget has cached AI suggestions
        $cache = $budget->suggested_tags_cache;
        if (empty($cache) || !isset($cache['matches_detailed'])) {
            // No AI suggestions available, return simple format as-is
            return $linkedTags;
        }

        $matchesDetailed = $cache['matches_detailed'];

        // Check if this category has AI suggestions with keywords
        if (isset($matchesDetailed[$categoryName])) {
            $aiData = $matchesDetailed[$categoryName];

            // If AI data has keywords, use the rich format
            if (isset($aiData['keywords']) && !empty($aiData['keywords'])) {
                Log::info("Enriching linked_tags for category '{$categoryName}' with AI keywords", [
                    'budget_id' => $budget->id,
                    'original_tags' => $linkedTags,
                    'ai_tags' => $aiData['tags'] ?? [],
                    'ai_keywords' => $aiData['keywords'],
                ]);

                return [
                    'tags' => $linkedTags, // Use tags from frontend (user's selection)
                    'keywords' => $aiData['keywords'], // Add keywords from AI analysis
                ];
            }
        }

        // No AI keywords found for this category, return simple format
        return $linkedTags;
    }

}
