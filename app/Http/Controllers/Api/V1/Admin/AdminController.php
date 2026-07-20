<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel de administración de la plataforma (solo roles admin / super-admin).
 *
 * Secciones: resumen (KPIs), usuarios (+ conceder/retirar premium manual),
 * pagos, planes (pricing) y novedades/actualizaciones para los usuarios.
 */
class AdminController extends Controller
{
    /** KPIs generales de la plataforma. */
    public function overview(): JsonResponse
    {
        $now = now();

        $revenueMonth = BillingPayment::where('status', 'approved')
            ->whereYear('paid_at', $now->year)->whereMonth('paid_at', $now->month)
            ->sum('amount_cents');

        return response()->json(['data' => [
            'users_total'      => User::count(),
            'users_new_30d'    => User::where('created_at', '>', $now->copy()->subDays(30))->count(),
            'trials_active'    => Subscription::where('status', 'trialing')
                ->where('trial_ends_at', '>', $now)->count(),
            'paying_active'    => Subscription::where('status', 'active')
                ->where('current_period_end', '>', $now)->count(),
            'expiring_7d'      => Subscription::whereIn('status', ['trialing', 'active'])
                ->whereBetween('current_period_end', [$now, $now->copy()->addDays(7)])->count(),
            'revenue_month_cop' => (int) ($revenueMonth / 100),
            'revenue_total_cop' => (int) (BillingPayment::where('status', 'approved')->sum('amount_cents') / 100),
            'payments_pending'  => BillingPayment::where('status', 'pending')->count(),
            'webhooks_failed_7d' => WebhookLog::where('status', 'failed')
                ->where('created_at', '>', $now->copy()->subDays(7))->count(),
            'recent_payments'   => BillingPayment::with('user:id,name,phone', 'plan:id,code,name')
                ->latest()->limit(8)->get()
                ->map(fn ($p) => [
                    'id'        => $p->id,
                    'user'      => $p->user?->name,
                    'plan'      => $p->plan?->name,
                    'amount_cop' => (int) ($p->amount_cents / 100),
                    'status'    => $p->status,
                    'method'    => $p->method,
                    'created_at' => $p->created_at,
                ]),
        ]]);
    }

    /** Usuarios con su estado de suscripción. Filtro por nombre/teléfono/email. */
    public function users(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $s)
                    ->orWhere('phone', 'like', $s)
                    ->orWhere('email', 'like', $s));
            })
            ->with(['activeSubscription.plan:id,code,name'])
            ->latest()
            ->paginate(20);

        $users->getCollection()->transform(function (User $u) {
            $sub = $u->activeSubscription;

            return [
                'id'         => $u->id,
                'name'       => $u->name,
                'phone'      => $u->phone,
                'email'      => $u->email,
                'created_at' => $u->created_at,
                'roles'      => $u->getRoleNames(),
                'subscription' => $sub ? [
                    'status'     => $sub->status,
                    'plan'       => $sub->plan?->name,
                    'is_premium' => $sub->isPremium(),
                    'ends_at'    => $sub->current_period_end ?? $sub->trial_ends_at,
                ] : null,
            ];
        });

        return response()->json($users);
    }

    /**
     * Concede premium manual (cortesía/soporte) por N días, o lo retira.
     * Queda como suscripción gateway 'manual' auditable.
     */
    public function setPremium(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:grant,revoke'],
            'days'   => ['required_if:action,grant', 'integer', 'min:1', 'max:730'],
        ]);

        $sub = $user->activeSubscription;

        if ($validated['action'] === 'revoke') {
            if ($sub) {
                $sub->update(['status' => 'canceled', 'canceled_at' => now(), 'auto_renew' => false]);
            }

            return response()->json(['data' => ['is_premium' => false]]);
        }

        $plan = Plan::where('code', 'pro_monthly')->first();
        $end = now()->addDays((int) $validated['days']);

        $attributes = [
            'plan_id'              => $plan?->id ?? $sub?->plan_id,
            'status'               => 'active',
            'gateway'              => 'manual',
            'current_period_start' => now(),
            'current_period_end'   => $end,
            'canceled_at'          => null,
            'cancel_at_period_end' => false,
        ];

        if ($sub) {
            $sub->update($attributes);
        } else {
            Subscription::create(['user_id' => $user->id] + $attributes);
        }

        return response()->json(['data' => ['is_premium' => true, 'ends_at' => $end]]);
    }

    /** Historial de pagos de toda la plataforma. */
    public function payments(Request $request): JsonResponse
    {
        $payments = BillingPayment::with('user:id,name,phone', 'plan:id,code,name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate(20);

        $payments->getCollection()->transform(fn (BillingPayment $p) => [
            'id'         => $p->id,
            'user'       => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'phone' => $p->user->phone] : null,
            'plan'       => $p->plan?->name,
            'reference'  => $p->reference,
            'amount_cop' => (int) ($p->amount_cents / 100),
            'status'     => $p->status,
            'method'     => $p->method,
            'paid_at'    => $p->paid_at,
            'created_at' => $p->created_at,
        ]);

        return response()->json($payments);
    }

    /** Planes (incluye inactivos) para gestión de pricing. */
    public function plans(): JsonResponse
    {
        return response()->json(['data' => Plan::orderBy('sort_order')->get()->map(fn (Plan $p) => [
            'id'          => $p->id,
            'code'        => $p->code,
            'name'        => $p->name,
            'description' => $p->description,
            'price_cop'   => $p->priceInCop(),
            'interval'    => $p->interval,
            'trial_days'  => $p->trial_days,
            'is_active'   => $p->is_active,
            'sort_order'  => $p->sort_order,
        ])]);
    }

    /** Edición de un plan (precio en COP, textos, prueba, visibilidad). */
    public function updatePlan(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price_cop'   => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'trial_days'  => ['sometimes', 'integer', 'min:0', 'max:90'],
            'is_active'   => ['sometimes', 'boolean'],
            'sort_order'  => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('price_cop', $validated)) {
            $validated['price_cents'] = $validated['price_cop'] * 100;
            unset($validated['price_cop']);
        }

        $plan->update($validated);

        return response()->json(['data' => ['ok' => true]]);
    }

    // ── Novedades / actualizaciones ─────────────────────────────────────

    public function announcements(): JsonResponse
    {
        return response()->json(['data' => Announcement::with('author:id,name')->latest()->limit(50)->get()]);
    }

    public function storeAnnouncement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'        => ['required', 'string', 'max:150'],
            'body'         => ['required', 'string', 'max:5000'],
            'type'         => ['required', 'in:update,news,maintenance'],
            'is_published' => ['required', 'boolean'],
        ]);

        $announcement = Announcement::create($validated + [
            'created_by'   => $request->user()->id,
            'published_at' => $validated['is_published'] ? now() : null,
        ]);

        return response()->json(['data' => $announcement], 201);
    }

    public function updateAnnouncement(Request $request, Announcement $announcement): JsonResponse
    {
        $validated = $request->validate([
            'title'        => ['sometimes', 'string', 'max:150'],
            'body'         => ['sometimes', 'string', 'max:5000'],
            'type'         => ['sometimes', 'in:update,news,maintenance'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        // Al publicar por primera vez fija la fecha de publicación.
        if (($validated['is_published'] ?? false) && ! $announcement->published_at) {
            $validated['published_at'] = now();
        }

        $announcement->update($validated);

        return response()->json(['data' => $announcement->fresh()]);
    }

    public function destroyAnnouncement(Announcement $announcement): JsonResponse
    {
        $announcement->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
