<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Console\Command;

/**
 * Otorga una suscripción de prueba a usuarios existentes que aún no tienen
 * ninguna. Necesario al activar el gating "Solo Pro" para no dejar fuera a los
 * usuarios que se registraron antes de tener el flujo de suscripción.
 *
 * Uso: php artisan subscriptions:backfill-trials [--days=14]
 */
class BackfillTrials extends Command
{
    protected $signature = 'subscriptions:backfill-trials {--days=14 : Días de prueba a otorgar}';

    protected $description = 'Da una suscripción de prueba a los usuarios sin ninguna suscripción';

    public function handle(SubscriptionManager $manager): int
    {
        $count = 0;

        User::whereDoesntHave('subscriptions')->chunkById(200, function ($users) use ($manager, &$count) {
            foreach ($users as $user) {
                if ($manager->startTrial($user)) {
                    $count++;
                }
            }
        });

        $this->info("Pruebas otorgadas a {$count} usuario(s) sin suscripción.");

        return self::SUCCESS;
    }
}
