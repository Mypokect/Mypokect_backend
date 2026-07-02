<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Avisa a los usuarios unos días antes de que venza su suscripción/prueba.
 *
 * - Con débito automático: recuerda que se cobrará solo (para que tengan saldo).
 * - Sin débito automático: invita a renovar para no perder el acceso.
 *
 * Dedupe: usa `renewal_reminded_at`. Solo re-avisa cuando el periodo avanzó
 * (renewal_reminded_at < current_period_start), evitando spam en el mismo ciclo.
 * Pensado para correr a diario vía scheduler.
 */
class RemindExpiringSubscriptions extends Command
{
    protected $signature = 'subscriptions:remind {--days=3 : Días de antelación del aviso}';

    protected $description = 'Notifica a los usuarios cuya suscripción/prueba está por vencer';

    public function handle(NotificationService $notifications): int
    {
        $days = (int) $this->option('days');
        $now = now();
        $threshold = $now->copy()->addDays($days);

        $subs = Subscription::whereIn('status', ['trialing', 'active'])
            ->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [$now, $threshold])
            ->where(function ($q) {
                $q->whereNull('renewal_reminded_at')
                    ->orWhereColumn('renewal_reminded_at', '<', 'current_period_start');
            })
            ->with(['user', 'plan'])
            ->get();

        $this->info("Suscripciones por vencer a avisar: {$subs->count()}");

        $sent = 0;
        foreach ($subs as $sub) {
            if (! $sub->user) {
                continue;
            }

            $daysLeft = max(0, (int) ceil($now->floatDiffInDays($sub->current_period_end, false)));
            $isTrial = $sub->status === 'trialing';
            $willAutoCharge = $sub->auto_renew && $sub->gateway_subscription_id;

            $title = $isTrial ? '⏳ Tu prueba está por terminar' : '🔔 Tu suscripción está por vencer';

            $when = $daysLeft <= 0 ? 'hoy' : ($daysLeft === 1 ? 'mañana' : "en {$daysLeft} días");
            $body = $willAutoCharge
                ? "Renovaremos tu plan {$when} con tu método guardado. Asegúrate de tener saldo."
                : ($isTrial
                    ? "Tu prueba termina {$when}. Activa tu membresía para no perder el acceso."
                    : "Tu plan vence {$when}. Renueva para seguir usando todas las funciones.");

            try {
                $notifications->sendToUser($sub->user, $title, $body, [
                    'type' => 'subscription',
                    'action' => 'renew',
                    'days_left' => (string) $daysLeft,
                ]);
                $sub->update(['renewal_reminded_at' => $now]);
                $sent++;
            } catch (Throwable $e) {
                $this->warn("Fallo avisando suscripción #{$sub->id}: {$e->getMessage()}");
            }
        }

        $this->info("Avisos enviados: {$sent}");

        return self::SUCCESS;
    }
}
