<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Billing\Domain\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inicia el checkout de suscripción con la pasarela (Wompi).
 *
 * MISMO endpoint para web (Vue) y app (Flutter): el cliente recibe una URL de
 * Web Checkout y redirige al usuario fuera de la app (estilo Spotify) para
 * esquivar las comisiones de las tiendas. El pago se confirma vía webhook.
 */
class CheckoutController extends Controller
{
    public function __construct(private PaymentGateway $gateway) {}

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,code'],
        ]);

        $plan = Plan::where('code', $data['plan'])
            ->where('is_active', true)
            ->firstOrFail();

        if ($plan->isFree()) {
            return response()->json([
                'error' => 'invalid_plan',
                'message' => 'El plan gratuito no requiere pago.',
            ], 422);
        }

        $user = $request->user();

        // Reutiliza una suscripción no-activa o crea una pendiente (trialing).
        $subscription = $user->subscriptions()
            ->whereIn('status', ['trialing', 'past_due', 'canceled', 'expired'])
            ->latest()
            ->first();

        if (! $subscription) {
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => 'trialing',
                'gateway' => 'wompi',
            ]);
        } else {
            $subscription->update(['plan_id' => $plan->id, 'gateway' => 'wompi']);
        }

        $subscription->setRelation('user', $user);

        try {
            $checkout = $this->gateway->createCheckout($subscription, $plan);
        } catch (\RuntimeException $e) {
            Log::error('Wompi checkout falló: '.$e->getMessage());

            return response()->json(['error' => 'gateway_unavailable', 'message' => 'La pasarela de pagos no está disponible: '.$e->getMessage()], 503);
        }

        return response()->json([
            'data' => [
                'checkout_url' => $checkout['checkout_url'],
                'reference' => $checkout['reference'],
                'plan' => $plan->code,
                'amount_cop' => $plan->priceInCop(),
            ],
        ]);
    }
}
