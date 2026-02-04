<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SavingGoal>
 */
class SavingGoalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'target_amount' => fake()->randomFloat(2, 100000, 10000000),
            'deadline' => fake()->dateTimeBetween('now', '+2 years'),
        ];
    }
}
