<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Announcement;
use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Reminder;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Estadísticas de uso de un usuario puntual: cuánto usa cada función y
     * su actividad reciente (page_views, plataforma). Se pide bajo demanda
     * (no en el listado) porque agrega varias tablas.
     */
    public function userUsage(User $user): JsonResponse
    {
        $since30 = now()->subDays(30);
        $platformExpr = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.platform')), 'web')";

        $events = AnalyticsEvent::where('user_id', $user->id);

        $lastActivity = (clone $events)->max('occurred_at');

        $platform30d = (clone $events)->where('event', 'page_view')
            ->where('occurred_at', '>', $since30)
            ->select(DB::raw("{$platformExpr} as platform"), DB::raw('COUNT(*) as visits'))
            ->groupBy('platform')
            ->pluck('visits', 'platform');

        $topSections = (clone $events)->where('event', 'page_view')
            ->where('occurred_at', '>', $since30)
            ->select(
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(properties, '$.section')) as section"),
                DB::raw('COUNT(*) as visits'),
            )
            ->groupBy('section')
            ->orderByDesc('visits')
            ->limit(5)
            ->get();

        return response()->json(['data' => [
            'counts' => [
                'movements'   => $user->movements()->count(),
                'budgets'     => DB::table('budgets')->where('user_id', $user->id)->count(),
                'goals'       => $user->savingGoals()->count(),
                'reminders'   => Reminder::where('user_id', $user->id)->count(),
                'scheduled'   => $user->scheduledTransactions()->count(),
            ],
            'page_views_7d'  => (clone $events)->where('event', 'page_view')->where('occurred_at', '>', now()->subDays(7))->count(),
            'page_views_30d' => (clone $events)->where('event', 'page_view')->where('occurred_at', '>', $since30)->count(),
            'last_activity_at' => $lastActivity,
            'platform_30d'   => $platform30d,
            'top_sections_30d' => $topSections,
        ]]);
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

    /**
     * Analítica de uso: tráfico por sección (page_views de web Y app móvil),
     * visitas por día y actividad real por función (filas creadas en cada
     * dominio, sin importar desde qué plataforma se crearon). Con esto se ve
     * comportamiento completo: qué usa la gente, y desde dónde.
     */
    public function analytics(): JsonResponse
    {
        $since30 = now()->subDays(30);
        $since14 = now()->subDays(14);

        // Eventos previos al tracking móvil no traen `platform`: se asumen
        // web (era la única fuente que existía).
        // CAST a UNSIGNED: SUM(CASE...) es DECIMAL en MySQL y PDO lo entrega
        // como string (rompería la suma en el frontend); COUNT() sí es BIGINT
        // nativo. Se fuerza el mismo tipo entero para las dos columnas.
        $platformExpr = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.platform')), 'web')";
        $webCount = DB::raw("CAST(SUM(CASE WHEN {$platformExpr} = 'web' THEN 1 ELSE 0 END) AS UNSIGNED) as web_visits");
        $mobileCount = DB::raw("CAST(SUM(CASE WHEN {$platformExpr} = 'mobile' THEN 1 ELSE 0 END) AS UNSIGNED) as mobile_visits");

        $sections = AnalyticsEvent::where('event', 'page_view')
            ->where('occurred_at', '>', $since30)
            ->select(
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(properties, '$.section')) as section"),
                DB::raw('COUNT(*) as visits'),
                DB::raw('COUNT(DISTINCT user_id) as unique_users'),
                $webCount,
                $mobileCount,
            )
            ->groupBy('section')
            ->orderByDesc('visits')
            ->get();

        $daily = AnalyticsEvent::where('event', 'page_view')
            ->where('occurred_at', '>', $since14)
            ->select(
                DB::raw('DATE(occurred_at) as day'),
                DB::raw('COUNT(*) as visits'),
                DB::raw('COUNT(DISTINCT user_id) as unique_users'),
                $webCount,
                $mobileCount,
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        // Actividad real por función: cuántas filas creó la gente en 30 días
        // (agrega ambas plataformas: la tabla no distingue el origen).
        $activity = collect([
            'movements'   => 'movements',
            'budgets'     => 'budgets',
            'goals'       => 'saving_goals',
            'goal_contributions' => 'goal_contributions',
            'reminders'   => 'reminders',
            'scheduled'   => 'scheduled_transactions',
        ])->map(fn (string $table) => DB::table($table)->where('created_at', '>', $since30)->count());

        $platformActive = function (\Carbon\Carbon $since) use ($platformExpr) {
            return AnalyticsEvent::where('occurred_at', '>', $since)
                ->select(DB::raw("{$platformExpr} as platform"), DB::raw('COUNT(DISTINCT user_id) as users'))
                ->groupBy('platform')
                ->pluck('users', 'platform');
        };

        return response()->json(['data' => [
            'active_users_7d'       => AnalyticsEvent::where('occurred_at', '>', now()->subDays(7))->distinct()->count('user_id'),
            'active_users_30d'      => AnalyticsEvent::where('occurred_at', '>', $since30)->distinct()->count('user_id'),
            'active_users_7d_by_platform'  => $platformActive(now()->subDays(7)),
            'active_users_30d_by_platform' => $platformActive($since30),
            'sections_30d'          => $sections,
            'daily_14d'             => $daily,
            'feature_activity_30d'  => $activity,
        ]]);
    }

    // ── Eventos (recordatorios de calendario de los usuarios) ───────────

    /** Recordatorios de toda la plataforma. Filtro por usuario/título/estado/tipo. */
    public function events(Request $request): JsonResponse
    {
        $events = Reminder::query()
            ->with('user:id,name,phone')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $s)
                    ->orWhere('category', 'like', $s)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $s)->orWhere('phone', 'like', $s)));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->latest('due_date')
            ->paginate(20);

        $events->getCollection()->transform(fn (Reminder $r) => [
            'id'         => $r->id,
            'title'      => $r->title,
            'type'       => $r->type,
            'amount'     => $r->amount,
            'category'   => $r->category,
            'note'       => $r->note,
            'due_date'   => $r->due_date,
            'recurrence' => $r->recurrence,
            'status'     => $r->status,
            'created_at' => $r->created_at,
            'user'       => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name, 'phone' => $r->user->phone] : null,
        ]);

        return response()->json($events);
    }

    /** Edición administrativa de un recordatorio (soporte/corrección). */
    public function updateEvent(Request $request, Reminder $reminder): JsonResponse
    {
        $validated = $request->validate([
            'title'    => ['sometimes', 'string', 'max:120'],
            'amount'   => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'note'     => ['sometimes', 'nullable', 'string'],
            'due_date' => ['sometimes', 'date'],
            'status'   => ['sometimes', 'in:pending,paid'],
        ]);

        $reminder->update($validated);

        return response()->json(['data' => $reminder->fresh('user:id,name,phone')]);
    }

    /** Elimina (soft delete) un recordatorio. */
    public function destroyEvent(Reminder $reminder): JsonResponse
    {
        $reminder->delete();

        return response()->json(['data' => ['deleted' => true]]);
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
