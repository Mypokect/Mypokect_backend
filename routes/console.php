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
