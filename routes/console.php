<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule reminder notifications every 15 minutes
Schedule::command('reminders:process-notifications')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Renovación de suscripciones (auto-cobro tarjeta/Nequi + expiración del resto)
Schedule::command('subscriptions:renew')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// Aviso a usuarios cuya suscripción/prueba está por vencer (3 días antes)
Schedule::command('subscriptions:remind')
    ->dailyAt('09:00')
    ->withoutOverlapping();

// Otorga (o quita) el rol de administrador del panel web por teléfono.
// Uso: php artisan admin:grant 3001234567  |  php artisan admin:grant 3001234567 --revoke
Artisan::command('admin:grant {phone} {--revoke}', function (string $phone) {
    $user = \App\Models\User::where('phone', $phone)->first();
    if (! $user) {
        $this->error("No existe un usuario con teléfono {$phone}.");

        return 1;
    }
    if ($this->option('revoke')) {
        $user->removeRole('super-admin');
        $this->info("Rol super-admin retirado a {$user->name}.");
    } else {
        $user->assignRole('super-admin');
        $this->info("{$user->name} ahora es super-admin del panel.");
    }

    return 0;
})->purpose('Gestiona el rol de administrador del panel web');
