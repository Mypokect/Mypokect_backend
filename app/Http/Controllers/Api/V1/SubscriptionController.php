<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Plan;
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
