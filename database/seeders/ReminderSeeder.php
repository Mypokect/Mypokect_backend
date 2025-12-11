<?php

namespace Database\Seeders;

use App\Models\Reminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ReminderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::first();

        if (! $user) {
            return;
        }

        $timezone = 'America/Bogota';
        $nowLocal = Carbon::now($timezone);

        // Recordatorio único pendiente (por ejemplo, pago de servicio)
        Reminder::create([
            'user_id' => $user->id,
            'title' => 'Pago de servicio de internet',
            'amount' => 120000,
            'category' => 'Servicios',
            'note' => 'Recuerda pagar antes de la fecha límite.',
            'due_date' => $nowLocal->copy()->addDays(3)->setTimezone('UTC'),
            'timezone' => $timezone,
            'recurrence' => 'none',
            'recurrence_params' => null,
            'notify_offset_minutes' => 1440,
            'status' => 'pending',
        ]);

        // Recordatorio mensual (por ejemplo, arriendo) el día 5 de cada mes
        Reminder::create([
            'user_id' => $user->id,
            'title' => 'Arriendo mensual',
            'amount' => 850000,
            'category' => 'Vivienda',
            'note' => 'Arriendo del apartamento.',
            'due_date' => Carbon::create($nowLocal->year, $nowLocal->month, 5, 9, 0, 0, $timezone)
                ->setTimezone('UTC'),
            'timezone' => $timezone,
            'recurrence' => 'monthly',
            'recurrence_params' => ['dayOfMonth' => 5],
            'notify_offset_minutes' => 2880, // 2 días antes
            'status' => 'pending',
        ]);

        // Recordatorio ya pagado (para probar estado "paid")
        Reminder::create([
            'user_id' => $user->id,
            'title' => 'Seguro del carro',
            'amount' => 450000,
            'category' => 'Seguros',
            'note' => 'Pago del seguro anual.',
            'due_date' => $nowLocal->copy()->subDays(10)->setTimezone('UTC'),
            'timezone' => $timezone,
            'recurrence' => 'none',
            'recurrence_params' => null,
            'notify_offset_minutes' => 1440,
            'status' => 'paid',
        ]);
    }
}
