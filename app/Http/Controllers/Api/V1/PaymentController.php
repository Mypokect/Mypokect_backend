<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Billing\Infrastructure\Gateways\WompiGateway;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pago con tarjeta guardada (débito automático real).
 *
 * La tarjeta se tokeniza en el cliente contra Wompi (los datos NUNCA tocan
 * nuestro servidor). Aquí creamos la fuente de pago reutilizable, ejecutamos el
 * primer cobro y, si el usuario dejó activado el débito automático, guardamos la
 * fuente para que el cron `subscriptions:renew` cobre solo en cada vencimiento.
 */
class PaymentController extends Controller
{
    public function __construct(
        private WompiGateway $gateway,
        private SubscriptionManager $manager,
    ) {}

    /** Tokens de aceptación (T&C + autorización de datos) para el checkout. */
    public function acceptance(): JsonResponse
    {
        return response()->json(['data' => $this->gateway->getAcceptance()]);
    }

    /** Cobra con una tarjeta tokenizada y activa/renueva la suscripción. */
    public function payWithCard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan'       => ['required', 'string', 'exists:plans,code'],
            'card_token' => ['required', 'string'],
            'email'      => ['required', 'email'],
            'auto_renew' => ['sometimes', 'boolean'],
            'brand'      => ['nullable', 'string', 'max:30'],
            'last_four'  => ['nullable', 'string', 'max:4'],
        ]);

        $plan = Plan::where('code', $data['plan'])->where('is_active', true)->firstOrFail();
        if ($plan->isFree()) {
            return response()->json(['error' => 'invalid_plan', 'message' => 'El plan gratuito no requiere pago.'], 422);
        }

        $user = $request->user();
        $autoRenew = (bool) ($data['auto_renew'] ?? true);

        // Guarda el email del usuario si aún no tiene (Wompi lo exige).
        if (empty($user->email)) {
            $user->forceFill(['email' => $data['email']])->save();
        }

        // Reutiliza una suscripción no-activa o crea una.
        $subscription = $user->subscriptions()
            ->whereIn('status', ['trialing', 'past_due', 'canceled', 'expired'])
            ->latest()
            ->first();

        if (! $subscription) {
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status'  => 'trialing',
                'gateway' => 'wompi',
            ]);
        }
        $subscription->update(['plan_id' => $plan->id, 'gateway' => 'wompi', 'auto_renew' => $autoRenew]);
        $subscription->setRelation('user', $user);

        try {
            $result = $this->gateway->chargeWithToken($subscription, $plan, 'CARD', $data['card_token'], $data['email']);
        } catch (\Throwable $e) {
            Log::error('Wompi pay-with-card falló', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'payment_failed', 'message' => 'No se pudo procesar el pago. Verifica los datos de la tarjeta.'], 422);
        }

        $tx = $result['transaction'];
        $wompiStatus = $tx['status'] ?? 'PENDING';

        // Concilia el resultado (activa la suscripción si quedó aprobado al instante).
        $this->manager->handleWompiTransaction([
            'data' => ['transaction' => array_merge($tx, ['reference' => $result['reference']])],
        ]);

        // Débito automático: guarda/limpia la fuente de pago según el toggle.
        $subscription->refresh();
        if ($autoRenew) {
            $subscription->update(['gateway_subscription_id' => (string) $result['payment_source_id']]);
            $this->savePaymentMethod($user->id, $result, $data);
        } else {
            $subscription->update(['gateway_subscription_id' => null]);
            PaymentMethod::where('user_id', $user->id)->delete();
        }

        return response()->json([
            'data' => [
                'wompi_status' => $wompiStatus,
                'transaction_id' => $tx['id'] ?? null,
                'reference' => $result['reference'],
                'is_premium' => (bool) $user->fresh()->isPremium(),
                'auto_renew' => $autoRenew,
            ],
        ]);
    }

    /** Activa/desactiva el débito automático de la suscripción del usuario. */
    public function autoRenew(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $data['enabled'];

        $subscription = $request->user()->activeSubscription;
        if (! $subscription) {
            return response()->json(['error' => 'no_subscription', 'message' => 'No hay una suscripción activa.'], 404);
        }

        $subscription->update(['auto_renew' => $enabled]);

        // Al desactivar, se olvida la fuente de pago (no se cobrará solo).
        if (! $enabled) {
            $subscription->update(['gateway_subscription_id' => null]);
            PaymentMethod::where('user_id', $request->user()->id)->delete();
        }

        return response()->json(['data' => ['auto_renew' => $enabled]]);
    }

    /** Método de pago guardado del usuario (para mostrar "Visa ****1234"). */
    public function paymentMethod(Request $request): JsonResponse
    {
        $pm = PaymentMethod::where('user_id', $request->user()->id)
            ->where('is_default', true)
            ->first();

        return response()->json([
            'data' => $pm ? [
                'brand' => $pm->brand,
                'last_four' => $pm->last_four,
                'type' => $pm->type,
            ] : null,
        ]);
    }

    /** Elimina la tarjeta guardada y apaga el débito automático. */
    public function destroyPaymentMethod(Request $request): JsonResponse
    {
        PaymentMethod::where('user_id', $request->user()->id)->delete();

        if ($subscription = $request->user()->activeSubscription) {
            $subscription->update(['auto_renew' => false, 'gateway_subscription_id' => null]);
        }

        return response()->json(['data' => ['removed' => true]]);
    }

    private function savePaymentMethod(int $userId, array $result, array $data): void
    {
        $source = $result['source'] ?? [];
        $public = $source['public_data'] ?? [];

        PaymentMethod::updateOrCreate(
            ['user_id' => $userId, 'is_default' => true],
            [
                'gateway' => 'wompi',
                'type' => 'card',
                'token' => (string) $result['payment_source_id'],
                'brand' => $data['brand'] ?? ($public['brand'] ?? null),
                'last_four' => $data['last_four'] ?? ($public['last_four'] ?? null),
            ],
        );
    }
}
