<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\BudgetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class BudgetCategoryFactory extends Factory
{
    protected $model = BudgetCategory::class;

    public function definition(): array
    {
        $amount = $this->faker->randomFloat(2, 10, 1000);

        return [
            'budget_id' => Budget::factory(),
            'name' => $this->faker->words(2, true),
            'amount' => $amount,
            'percentage' => $this->faker->randomFloat(2, 0, 100),
            'reason' => $this->faker->optional()->sentence(),
            'order' => $this->faker->numberBetween(0, 10),
        ];
    }

    public function forBudget(Budget $budget): self
    {
        return $this->state(fn (array $attributes) => [
            'budget_id' => $budget->id,
        ]);
    }
}
