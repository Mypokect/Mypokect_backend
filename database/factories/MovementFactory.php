<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Movement>
 */
class MovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['expense', 'income']),
            'amount' => fake()->randomFloat(2, 1, 1000),
            'description' => fake()->sentence(),
            'payment_method' => fake()->randomElement(['cash', 'digital']),
            'has_invoice' => fake()->boolean(),
        ];
    }
}
