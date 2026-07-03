<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fuente de verdad del estado de suscripción para TODOS los clientes
 * (Flutter, Vue, web). Cada cliente consulta /subscription/status y gatea
 * sus features premium según `is_premium`.
 */
class SubscriptionController extends Controller
{
    /** Estado de la suscripción del usuario autenticado. */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $sub = $user->activeSubscription;

        $daysUntilRenewal = $sub?->current_period_end
            ? max(0, (int) ceil(now()->floatDiffInDays($sub->current_period_end, false)))
            : null;

        $pm = PaymentMethod::where('user_id', $user->id)->where('is_default', true)->first();

        return response()->json([
            'data' => [
                'status'     => $sub?->status ?? 'none',
                // ¿Puede activar la prueba gratis? (nunca ha tenido suscripción)
                'trial_available' => ! $sub && $user->subscriptions()->doesntExist(),
                'plan'       => $sub?->plan?->code,
                'plan_name'  => $sub?->plan?->name,
                'gateway'    => $sub?->gateway,
                'period_end' => $sub?->current_period_end,
                'trial_ends_at' => $sub?->trial_ends_at,
                'is_premium' => (bool) $sub?->isPremium(),
                'auto_renew' => (bool) ($sub?->auto_renew ?? false),
                'days_until_renewal' => $daysUntilRenewal,
                'payment_method' => $pm ? [
                    'brand' => $pm->brand,
                    'last_four' => $pm->last_four,
                    'type' => $pm->type,
                ] : null,
            ],
        ]);
    }

    /**
     * Activa la prueba gratis de 14 días (una sola vez por usuario).
     * El registro ya la crea automáticamente; esto cubre cuentas anteriores
     * al lanzamiento del trial (CTA "Comenzar mis 14 días gratis" del front).
     */
    public function startTrial(Request $request, SubscriptionManager $manager): JsonResponse
    {
        $user = $request->user();

        if ($user->subscriptions()->exists()) {
            return response()->json(['message' => 'Ya usaste tu prueba gratis. Elige un plan para continuar.'], 422);
        }

        $sub = $manager->startTrial($user);

        if (! $sub) {
            return response()->json(['message' => 'No pudimos activar tu prueba. Intenta de nuevo.'], 500);
        }

        return response()->json([
            'data' => [
                'status'        => $sub->status,
                'trial_ends_at' => $sub->trial_ends_at,
                'is_premium'    => (bool) $sub->isPremium(),
            ],
        ], 201);
    }

    /** Catálogo público de planes activos (para pricing / checkout). */
    public function plans(): JsonResponse
    {
        $plans = Plan::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $p) => [
                'code'        => $p->code,
                'name'        => $p->name,
                'description' => $p->description,
                'price_cop'   => $p->priceInCop(),
                'currency'    => $p->currency,
                'interval'    => $p->interval,
                'trial_days'  => $p->trial_days,
                'features'    => $p->features,
            ]);

        return response()->json(['data' => $plans]);
    }
}
