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
