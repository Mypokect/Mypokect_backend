<?php

namespace App\Console\Commands;

use App\Domains\Billing\Domain\Contracts\PaymentGateway;
use App\Domains\Billing\Infrastructure\Gateways\WompiGateway;
use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Renovación de suscripciones (modelo híbrido):
 *  - Con fuente de pago guardada (tarjeta/Nequi): cobra automáticamente.
 *  - Sin fuente (PSE, efectivo, etc.): marca past_due/expired para que el
 *    usuario renueve por checkout (un recordatorio lo invita a la web).
 *
 * Pensado para correr a diario vía scheduler.
 */
class RenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:renew {--grace-days=3 : Días de gracia antes de expirar}';

    protected $description = 'Cobra renovaciones automáticas y marca vencidas las que requieren acción del usuario';

    public function handle(PaymentGateway $gateway, SubscriptionManager $manager): int
    {
        $due = Subscription::where('status', 'active')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->with(['plan', 'user'])
            ->get();

        $this->info("Suscripciones vencidas a procesar: {$due->count()}");

        foreach ($due as $subscription) {
            // Auto-renovación solo si hay fuente de pago tokenizada y el adapter es Wompi.
            if ($subscription->gateway_subscription_id && $gateway instanceof WompiGateway) {
                $this->autoRenew($subscription, $gateway, $manager);

                continue;
            }

            // Sin fuente reutilizable: ventana de gracia y luego expira.
            $graceEnd = $subscription->current_period_end?->copy()->addDays((int) $this->option('grace-days'));
            $subscription->update([
                'status' => $graceEnd && $graceEnd->isPast() ? 'expired' : 'past_due',
                'grace_ends_at' => $graceEnd,
            ]);
        }

        return self::SUCCESS;
    }

    private function autoRenew(Subscription $subscription, WompiGateway $gateway, SubscriptionManager $manager): void
    {
        $plan = $subscription->plan;
        $email = $subscription->user?->email;
        $reference = 'sub'.$subscription->id.'-'.$plan->code.'-renew-'.Str::lower(Str::random(8));

        $payment = BillingPayment::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'gateway' => 'wompi',
            'reference' => $reference,
            'amount_cents' => (int) $plan->price_cents,
            'currency' => 'COP',
            'status' => 'pending',
        ]);

        try {
            $tx = $gateway->chargePaymentSource(
                (int) $subscription->gateway_subscription_id,
                (int) $plan->price_cents,
                $reference,
                (string) $email,
            );

            // Si el cobro fue inmediato, aplica el resultado; si quedó PENDING llegará por webhook.
            if (($tx['status'] ?? null)) {
                $manager->handleWompiTransaction(['data' => ['transaction' => array_merge($tx, ['reference' => $reference])]]);
            }
        } catch (Throwable $e) {
            $payment->update(['status' => 'rejected', 'raw_response' => ['error' => $e->getMessage()]]);
            $subscription->update(['status' => 'past_due']);
            $this->warn("Fallo al renovar suscripción #{$subscription->id}: {$e->getMessage()}");
        }
    }
}
