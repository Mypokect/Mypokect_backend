<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
        $sub = $request->user()->activeSubscription;

        return response()->json([
            'data' => [
                'status'     => $sub?->status ?? 'none',
                'plan'       => $sub?->plan?->code,
                'gateway'    => $sub?->gateway,
                'period_end' => $sub?->current_period_end,
                'trial_ends_at' => $sub?->trial_ends_at,
                'is_premium' => (bool) $sub?->isPremium(),
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
