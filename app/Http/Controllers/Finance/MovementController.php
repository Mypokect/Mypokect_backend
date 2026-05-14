<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Movement\CreateMovementRequest;
use App\Http\Requests\Movement\VoiceSuggestionRequest;
use App\Http\Resources\MovementResource;
use App\Http\Traits\ApiResponse;
use App\Models\Budget;
use App\Models\Movement;
use App\Models\Tag;
use App\Services\MovementAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MovementController extends Controller
{
    use ApiResponse;

    protected MovementAIService $aiService;

    public function __construct(MovementAIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * List all movements.
     *
     * Returns all movements for the authenticated user, ordered by most recent, with their associated tag.
     */
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $user    = Auth::user();
            $perPage = min((int) $request->query('per_page', 20), 100);

            Log::info("User {$user->id} is requesting movements list (per_page={$perPage}).");

            $movements = $user->movements()
                ->with('tag')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return $this->successResponse([
                'data'       => MovementResource::collection($movements),
                'pagination' => [
                    'current_page' => $movements->currentPage(),
                    'last_page'    => $movements->lastPage(),
                    'per_page'     => $movements->perPage(),
                    'total'        => $movements->total(),
                    'has_more'     => $movements->hasMorePages(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching movements: '.$e->getMessage());

            return $this->errorResponse('Error al obtener movimientos: ' . $this->safeMessage($e));
        }
    }

    /**
     * Create a movement.
     *
     * Creates an income or expense movement. Automatically finds or creates the tag by name.
     */
    public function store(CreateMovementRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // Per-user rate lock: reject if last successful save was < 2 seconds ago
            $lockKey = "movement_save_lock_{$user->id}";
            if (Cache::has($lockKey)) {
                return $this->errorResponse('Demasiadas solicitudes. Espera un momento antes de registrar otro movimiento.', 429);
            }

            // Idempotency check: reject identical movement within the last 5 seconds
            $duplicate = Movement::where('user_id', $user->id)
                ->where('amount', $request->amount)
                ->where('description', $request->description ?? 'Movimiento')
                ->where('created_at', '>=', Carbon::now()->subSeconds(5))
                ->exists();

            if ($duplicate) {
                return $this->errorResponse('Registro duplicado detectado', 422);
            }

            DB::beginTransaction();

            // Handle tag: find or create by name
            $tagId = null;
            if ($request->has('tag_name') && ! empty($request->tag_name)) {
                $tagName = ucfirst(strtolower(trim($request->tag_name)));

                $tag = Tag::firstOrCreate(
                    ['user_id' => $user->id, 'name' => $tagName]
                );

                $tagId = $tag->id;
            }

            // Create movement
            $movement = Movement::create([
                'type'                => $request->type,
                'amount'              => $request->amount,
                'description'         => $request->description ?? 'Movimiento',
                'payment_method'      => $request->payment_method,
                'has_invoice'         => $request->has_invoice ?? false,
                'is_business_expense' => $request->is_business_expense ?? false,
                'rent_type'           => $request->type === 'income' ? ($request->rent_type ?? null) : null,
                'user_id'             => $user->id,
                'tag_id'              => $tagId,
                'is_digital'          => $request->input('is_digital', $request->payment_method === 'digital'),
                'location_name'       => $request->input('location_name'),
            ]);

            // Load tag relationship for response
            $movement->load('tag');

            DB::commit();

            // Set per-user lock for 2 seconds after successful save
            Cache::put($lockKey, true, now()->addSeconds(2));

            Log::info("Movement {$movement->id} created successfully by user {$user->id}", [
                'rent_type'           => $movement->rent_type,
                'is_business_expense' => $movement->is_business_expense,
            ]);

            // ── Sincronización Presupuesto ─────────────────────────────────────
            $budgetImpact = $this->computeBudgetImpact($user, $movement);

            // ── Alcancía con Fuga (Ahorro Reactivo) ───────────────────────────
            $leakInfo = $this->applyLeakyBucket($user, $movement);

            return $this->createdResponse([
                'movement'      => new MovementResource($movement),
                'budget_impact' => $budgetImpact,
                'leak_info'     => $leakInfo,
            ], 'Movimiento creado');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating movement: '.$e->getMessage());

            return $this->errorResponse('Error al crear movimiento: ' . $this->safeMessage($e));
        }
    }

    /**
     * Alcancía con Fuga — resta del saldo protegido cuando el gasto supera el techo diario.
     * Devuelve null si no aplica o si no hay fuga en este movimiento.
     */
    private function applyLeakyBucket($user, $movement): ?array
    {
        if ($movement->type !== 'expense') {
            return null;
        }

        if (! Schema::hasColumn('users', 'challenge_savings_balance')) {
            return null;
        }

        $user->refresh();

        if (! $user->has_active_challenge || $user->savings_mode_pct <= 0) {
            return null;
        }

        $now          = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth   = $now->copy()->endOfMonth();
        $startOfDay   = $now->copy()->startOfDay();
        $endOfDay     = $now->copy()->endOfDay();

        $incomesMes = (float) $user->movements()
            ->where('type', 'income')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        if ($incomesMes <= 0) {
            return null;
        }

        $metaAhorro  = $incomesMes * (float) $user->savings_mode_pct;
        $techoDiario = ($incomesMes - $metaAhorro) / 30;

        // Gastos totales de hoy (el nuevo movimiento ya está en DB tras commit)
        $gastosHoy      = (float) $user->movements()
            ->where('type', 'expense')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->sum('amount');

        $gastosHoyAntes  = $gastosHoy - (float) $movement->amount;

        $excesoAnterior  = max(0.0, $gastosHoyAntes - $techoDiario);
        $excesoNuevo     = max(0.0, $gastosHoy - $techoDiario);
        $fugaIncremental = round($excesoNuevo - $excesoAnterior, 2);

        if ($fugaIncremental <= 0) {
            return null;
        }

        $saldoActual  = (float) $user->challenge_savings_balance;
        $nuevoSaldo   = max(0.0, round($saldoActual - $fugaIncremental, 2));

        $user->update(['challenge_savings_balance' => $nuevoSaldo]);

        Log::info("Leaky bucket user {$user->id}: fuga={$fugaIncremental}, balance {$saldoActual} -> {$nuevoSaldo}");

        return [
            'fuga_aplicada'            => $fugaIncremental,
            'techo_diario'             => round($techoDiario, 2),
            'gastos_hoy'               => round($gastosHoy, 2),
            'ahorro_protegido_actual'  => $nuevoSaldo,
        ];
    }

    /**
     * Finds the first active budget whose category tags match the movement's tag,
     * computes the updated monto_gastado, and returns a compact impact summary.
     * Only applies to expense movements with a tag.
     */
    private function computeBudgetImpact($user, Movement $movement): ?array
    {
        if ($movement->type !== 'expense' || ! $movement->tag_id) {
            return null;
        }

        $tagName = $movement->tag?->name;
        if (! $tagName) {
            return null;
        }

        $today = now()->toDateString();

        $activeBudgets = Budget::where('user_id', $user->id)
            ->whereIn('status', ['active', 'draft'])
            ->where('date_from', '<=', $today)
            ->where('date_to', '>=', $today)
            ->with('categories')
            ->get();

        foreach ($activeBudgets as $budget) {
            foreach ($budget->categories as $category) {
                $linkedTags = $this->resolveLinkedTagNames($category->linked_tags ?? []);
                if (! in_array($tagName, $linkedTags, true)) {
                    continue;
                }

                $from = $budget->date_from->format('Y-m-d').' 00:00:00';
                $to   = $budget->date_to->format('Y-m-d').' 23:59:59';

                $spent = (float) Movement::where('user_id', $user->id)
                    ->where('type', 'expense')
                    ->whereBetween('created_at', [$from, $to])
                    ->whereHas('tag', fn ($q) => $q->whereIn('name', $linkedTags))
                    ->sum('amount');

                $budgeted = (float) $category->amount;
                $ratio    = $budgeted > 0 ? $spent / $budgeted : 0;

                Log::info("Budget impact computed", [
                    'budget_id' => $budget->id,
                    'category'  => $category->name,
                    'spent'     => $spent,
                    'budgeted'  => $budgeted,
                ]);

                return [
                    'budget_id'           => $budget->id,
                    'budget_title'        => $budget->title,
                    'category'            => $category->name,
                    'monto_gastado'       => round($spent, 2),
                    'monto_presupuestado' => $budgeted,
                    'porcentaje_progreso' => round($ratio * 100, 1),
                    'estado_color'        => $ratio >= 1.0 ? 'red' : ($ratio >= 0.75 ? 'yellow' : 'green'),
                ];
            }
        }

        return null;
    }

    /**
     * Normalizes linked_tags from BudgetCategory into a flat string array.
     * Supports both simple array format and rich {'tags': [...], 'keywords': [...]} format.
     */
    private function resolveLinkedTagNames($linkedData): array
    {
        if (! is_array($linkedData) || empty($linkedData)) {
            return [];
        }
        if (isset($linkedData['tags']) && is_array($linkedData['tags'])) {
            return array_values(array_filter($linkedData['tags'], 'is_string'));
        }
        if (array_key_exists(0, $linkedData)) {
            return array_values(array_filter($linkedData, 'is_string'));
        }

        return [];
    }

    /**
     * Suggest movement from voice.
     *
     * Uses AI to parse a voice transcription and suggest movement fields (amount, tag, type, description).
     */
    public function suggestFromVoice(VoiceSuggestionRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            Log::info("User {$user->id} is requesting voice suggestion for: {$request->transcripcion}");

            $suggestion = $this->aiService->suggestFromVoice(
                $request->transcripcion,
                $user
            );

            Log::info('Voice suggestion generated successfully', ['suggestion' => $suggestion]);

            if ($suggestion['error_type'] === 'insufficient_data') {
                Log::warning("Voice suggestion insufficient_data for user {$user->id}: {$request->transcripcion}");
                return $this->errorResponse('No se pudo interpretar el comando de voz.', 422);
            }

            return $this->successResponse(['movement_suggestion' => $suggestion]);

        } catch (\Exception $e) {
            Log::error('Error processing voice suggestion: '.$e->getMessage());

            return $this->errorResponse('Error en proceso de IA: ' . $this->safeMessage($e));
        }
    }
}
