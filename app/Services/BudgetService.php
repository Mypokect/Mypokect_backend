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
     * @param User $user
     * @param array $data
     * @return Budget
     * @throws \InvalidArgumentException
     */
    public function createManualBudget(User $user, array $data): Budget
    {
        return DB::transaction(function () use ($user, $data) {
            $totalAmount = (float) $data['total_amount'];
            $categories = $data['categories'];

            // Validate sum
            $categoriesSum = array_sum(array_column($categories, 'amount'));
            Log::info("BudgetService: Math Check - Total: $totalAmount, Sum: $categoriesSum");

            if (abs($categoriesSum - $totalAmount) > 0.01) {
                Log::error("BudgetService: Math mismatch. Sum ($categoriesSum) != Total ($totalAmount)");
                throw new \InvalidArgumentException("Sum of categories (\$$categoriesSum) does not match total amount (\$$totalAmount)");
            }

            // Create budget
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
                'status' => 'draft',
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
     * @param User $user
     * @param array $data
     * @return Budget
     * @throws \InvalidArgumentException
     */
    public function createAIBudget(User $user, array $data): Budget
    {
        return DB::transaction(function () use ($user, $data) {
            $totalAmount = (float) $data['total_amount'];
            $categories = $data['categories'];

            // Validate sum
            $categoriesSum = array_sum(array_column($categories, 'amount'));
            if (abs($categoriesSum - $totalAmount) > 0.01) {
                Log::error("BudgetService: Save AI Math mismatch. Sum: $categoriesSum, Total: $totalAmount");
                throw new \InvalidArgumentException('Sum of categories does not match total amount');
            }

            $budget = Budget::create([
                'user_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'total_amount' => $totalAmount,
                'mode' => 'ai',
                'language' => $data['language'] ?? 'es',
                'plan_type' => $data['plan_type'] ?? 'other',
                'status' => 'draft',
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
     * @param Budget $budget
     * @param array $data
     * @return Budget
     * @throws \InvalidArgumentException
     */
    public function updateBudget(Budget $budget, array $data): Budget
    {
        $totalAmount = (float) $data['total_amount'];
        $categoriesInput = $data['categories'];

        // Validate sum
        $categoriesSum = array_sum(array_column($categoriesInput, 'amount'));
        if (abs($categoriesSum - $totalAmount) > 0.1) {
            throw new \InvalidArgumentException("La suma de categorías ($categoriesSum) no coincide con el total ($totalAmount).");
        }

        // Update budget
        $budget->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'total_amount' => $totalAmount,
        ]);

        // Sync categories
        $this->syncCategories($budget, $categoriesInput, $totalAmount);

        $budget->load('categories');

        return $budget;
    }

    /**
     * Add a category to an existing budget.
     *
     * @param Budget $budget
     * @param array $data
     * @return BudgetCategory
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

        $category = BudgetCategory::create([
            'budget_id' => $budget->id,
            'name' => $data['name'],
            'amount' => $amount,
            'percentage' => round(($amount / $budget->total_amount) * 100, 2),
            'reason' => $data['reason'] ?? '',
            'order' => $budget->categories()->count(),
        ]);

        return $category;
    }

    /**
     * Delete budget and all its categories.
     *
     * @param Budget $budget
     * @return void
     */
    public function deleteBudget(Budget $budget): void
    {
        Log::info("BudgetService: Deleting budget {$budget->id}");
        $budget->categories()->delete();
        $budget->delete();
    }

    /**
     * Create categories for a budget.
     *
     * @param Budget $budget
     * @param array $categories
     * @param float $totalAmount
     * @return void
     */
    private function createCategories(Budget $budget, array $categories, float $totalAmount): void
    {
        foreach ($categories as $index => $category) {
            $amount = (float) $category['amount'];
            BudgetCategory::create([
                'budget_id' => $budget->id,
                'name' => $category['name'],
                'amount' => $amount,
                'percentage' => round(($amount / $totalAmount) * 100, 2),
                'reason' => $category['reason'] ?? '',
                'order' => $index,
            ]);
        }
    }

    /**
     * Sync categories with budget (update, delete, create).
     *
     * @param Budget $budget
     * @param array $categoriesInput
     * @param float $totalAmount
     * @return void
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

            if (isset($catData['id']) && $catData['id']) {
                // Update existing
                $category = BudgetCategory::find($catData['id']);
                if ($category && $category->budget_id == $budget->id) {
                    $category->update([
                        'name' => $catData['name'],
                        'amount' => $amount,
                        'percentage' => $pct,
                        'reason' => $catData['reason'] ?? '',
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
                    'order' => $index,
                ]);
            }
        }
    }
}
