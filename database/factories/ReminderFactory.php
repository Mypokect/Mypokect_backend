<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReminderFactory extends Factory
{
    protected $model = Reminder::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(3),
            'amount' => $this->faker->randomFloat(2, 10000, 1000000),
            'category' => $this->faker->randomElement(['Tarjetas', 'Servicios', 'Vivienda', 'Transporte']),
            'note' => $this->faker->optional()->sentence(),
            'due_date' => $this->faker->dateTimeBetween('now', '+3 months'),
            'timezone' => 'America/Bogota',
            'recurrence' => 'none',
            'recurrence_params' => null,
            'notify_offset_minutes' => 1440,
            'status' => 'pending',
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'recurrence' => 'monthly',
            'recurrence_params' => ['dayOfMonth' => $this->faker->numberBetween(1, 28)],
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
        ]);
    }
}
