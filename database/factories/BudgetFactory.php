<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'total_amount' => $this->faker->randomFloat(2, 100, 10000),
            'mode' => $this->faker->randomElement(['manual', 'ai']),
            'language' => $this->faker->randomElement(['es', 'en']),
            'plan_type' => $this->faker->randomElement(['travel', 'event', 'party', 'purchase', 'project', 'other']),
            'status' => $this->faker->randomElement(['draft', 'active', 'archived']),
        ];
    }

    public function manual(): self
    {
        return $this->state(fn (array $attributes) => [
            'mode' => 'manual',
        ]);
    }

    public function ai(): self
    {
        return $this->state(fn (array $attributes) => [
            'mode' => 'ai',
        ]);
    }

    public function active(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function draft(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    public function spanish(): self
    {
        return $this->state(fn (array $attributes) => [
            'language' => 'es',
        ]);
    }

    public function english(): self
    {
        return $this->state(fn (array $attributes) => [
            'language' => 'en',
        ]);
    }
}
