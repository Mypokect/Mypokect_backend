<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\ReminderSeeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Crea un usuario de prueba compatible con el esquema (login por teléfono)
        $user = User::first();

        if (! $user) {
            $user = User::create([
                'name' => 'Test User',
                'country_code' => 'CO',
                'phone' => '3000000000',
                'password' => Hash::make('password'),
            ]);
        }

        $this->call(ReminderSeeder::class);
    }
}
