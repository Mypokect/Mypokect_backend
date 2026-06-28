<?php

namespace App\Http\Controllers\Finance;

use App\Helpers\SchemaCache;
use App\Http\Controllers\Controller;
use App\Http\Requests\Movement\CreateMovementRequest;
use App\Http\Requests\Movement\VoiceSuggestionRequest;
use App\Http\Resources\MovementResource;
use App\Http\Traits\ApiResponse;
use App\Models\Budget;
use App\Models\Movement;
use App\Models\Tag;
use App\Services\MovementAIService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $perPage = min((int) $request->query('per_page', 20), 100);
            $month   = (int) $request->query('month', 0);
            $year    = (int) $request->query('year', 0);

            Log::info("User {$user->id} is requesting movements list (per_page={$perPage}, month={$month}, year={$year}).");

            $query = $user->movements()->with('tag')->orderBy('created_at', 'desc');

            if ($month > 0 && $year > 0) {
                $query->whereYear('created_at', $year)->whereMonth('created_at', $month);
            }

            $movements = $query->paginate($perPage);

            return $this->successResponse([
                'data' => MovementResource::collection($movements),
                'pagination' => [
                    'current_page' => $movements->currentPage(),
                    'last_page' => $movements->lastPage(),
                    'per_page' => $movements->perPage(),
                    'total' => $movements->total(),
                    'has_more' => $movements->hasMorePages(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching movements: '.$e->getMessage());

            return $this->errorResponse('Error al obtener movimientos: '.$this->safeMessage($e));
        }
    }

    /**
     * Create a movement.
     *
     * Creates an income or expense movement. Automatically finds or creates the tag by name.
     */
    public function store(CreateMovementRequest $request): JsonResponse
    {
        // Inicializar $lock fuera del try para que el catch siempre pueda accederlo.
        // Si $lock nunca se asigna (excepción antes de Cache::lock), el catch lo
        // omite con seguridad gracias al check `if ($lock)`.
        $lock = null;

        try {
            $user = Auth::user();

            // Atomic per-user lock — prevents race conditions from simultaneous taps.
            // Cache::lock() is a test-and-set: the first caller acquires it; any
            // concurrent request immediately gets false (non-blocking) and returns 429.
            // TTL of 3 s acts as the cooldown window after a successful save.
            $lock = Cache::lock("movement_save_{$user->id}", 3);
            if (! $lock->get()) {
                return $this->errorResponse('Demasiadas solicitudes. Espera un momento antes de registrar otro movimiento.', 429);
            }

            // Idempotency check: reject identical movement within the last 5 seconds
            $duplicate = Movement::where('user_id', $user->id)
                ->where('amount', $request->amount)
                ->where('description', $request->description ?? 'Movimiento')
                ->where('created_at', '>=', Carbon::now()->subSeconds(5))
                ->exists();

            if ($duplicate) {
                $lock->release();

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
                'type' => $request->type,
                'amount' => $request->amount,
                'description' => $request->description ?? 'Movimiento',
                'payment_method' => $request->payment_method,
                'has_invoice' => $request->has_invoice ?? false,
                'is_business_expense' => $request->is_business_expense ?? false,
                'rent_type' => $request->type === 'income' ? ($request->rent_type ?? null) : null,
                'user_id' => $user->id,
                'tag_id' => $tagId,
                'is_digital' => $request->input('is_digital', $request->payment_method === 'digital'),
                'location_name' => $request->input('location_name'),
            ]);

            // Load tag relationship for response
            $movement->load('tag');

            DB::commit();

            // Invalidate home/summary caches so the next request reflects the new movement
            $this->invalidateUserSummaryCache($user->id);

            // Lock auto-expires (3s TTL set at acquisition = cooldown window).
            // No manual release needed after a successful save.

            Log::info("Movement {$movement->id} created successfully by user {$user->id}", [
                'rent_type' => $movement->rent_type,
                'is_business_expense' => $movement->is_business_expense,
            ]);

            // ── Side effects post-commit (presupuesto + alcancía) ──────────────
            // Se ejecutan DESPUÉS del commit exitoso. Cualquier error aquí se
            // registra en el log pero NO falla la respuesta: el movimiento ya
            // fue guardado y el usuario debe recibir una respuesta de éxito.
            $budgetImpact = null;
            $leakInfo = null;
            try {
                $budgetImpact = $this->computeBudgetImpact($user, $movement);
                $leakInfo = $this->applyLeakyBucket($user, $movement);
                $this->recalculateSavingsChallenge($user);
            } catch (\Exception $sideEffectException) {
                Log::error('Error en side effects post-guardado (el movimiento SÍ fue guardado)', [
                    'movement_id' => $movement->id,
                    'user_id' => $user->id,
                    'error' => $sideEffectException->getMessage(),
                    'file' => $sideEffectException->getFile(),
                    'line' => $sideEffectException->getLine(),
                ]);
            }

            return $this->createdResponse([
                'movement' => new MovementResource($movement),
                'budget_impact' => $budgetImpact,
                'leak_info' => $leakInfo,
            ], 'Movimiento creado');

        } catch (\Exception $e) {
            DB::rollBack();
            // Liberar el lock solo si fue adquirido; si la excepción ocurrió
            // antes de Cache::lock(), $lock es null y no hay nada que liberar.
            if ($lock) {
                $lock->release();
            }
            Log::error('Error creating movement: '.$e->getMessage());

            return $this->errorResponse('Error al crear movimiento: '.$this->safeMessage($e));
        }
    }

    /**
     * Full monthly recalculation of the savings challenge balance.
     * Sums the daily excess over the spending ceiling for every day of the current month,
     * then writes the resulting protected balance back to the user record.
     * Called after every movement mutation (create, update, delete).
     */
    /**
     * Bust home-data and financial-summary caches for a user.
     * Must be called after every movement mutation (create, update, delete)
     * so the next homeData/financialSummary request returns fresh numbers.
     */
    private function invalidateUserSummaryCache(int $userId): void
    {
        // Delete up to 24 month-slots. We don't know which months are cached,
        // so we wipe 3 months: last month, current month, next month.
        $tz = 'America/Bogota';
        foreach ([-1, 0, 1] as $offset) {
            $dt = now($tz)->addMonths($offset);
            Cache::forget("home_data:{$userId}:{$dt->year}:{$dt->month}");
            Cache::forget("fin_summary:{$userId}:{$dt->year}:{$dt->month}");
        }
    }

    private function recalculateSavingsChallenge($user): void
    {
        if (! SchemaCache::hasColumn('users', 'challenge_savings_balance')) {
            return;
        }
        if (! SchemaCache::hasColumn('users', 'has_active_challenge')) {
            return;
        }

        $user->refresh();

        if (! $user->has_active_challenge || $user->savings_mode_pct <= 0) {
            return;
        }

        $tz = 'America/Bogota';
        $now = Carbon::now($tz);
        $startOfMonth = $now->copy()->startOfMonth()->utc()->toDateTimeString();
        $endOfMonth = $now->copy()->endOfMonth()->utc()->toDateTimeString();

        $incomesMes = (float) $user->movements()
            ->where('type', 'income')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        if ($incomesMes <= 0) {
            return;
        }

        $metaAhorro = $incomesMes * (float) $user->savings_mode_pct;
        $techoDiario = ($incomesMes - $metaAhorro) / 30;

        // Single query: group expenses by local date to respect Colombia midnight
        $rows = DB::select(
            "SELECT DATE(CONVERT_TZ(created_at, '+00:00', '-05:00')) AS dia,
                    SUM(amount) AS total_dia
             FROM movements
             WHERE user_id = :uid AND type = 'expense'
               AND created_at BETWEEN :start AND :end
             GROUP BY dia",
            ['uid' => $user->id, 'start' => $startOfMonth, 'end' => $endOfMonth]
        );

        $totalFuga = 0.0;
        foreach ($rows as $row) {
            $totalFuga += max(0.0, (float) $row->total_dia - $techoDiario);
        }

        $nuevoSaldo = max(0.0, round($metaAhorro - $totalFuga, 2));
        $user->update(['challenge_savings_balance' => $nuevoSaldo]);

        Log::info("Savings recalculated for user {$user->id}: balance={$nuevoSaldo}, fuga_total={$totalFuga}");
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

        if (! SchemaCache::hasColumn('users', 'challenge_savings_balance')) {
            return null;
        }

        $user->refresh();

        if (! $user->has_active_challenge || $user->savings_mode_pct <= 0) {
            return null;
        }

        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $startOfDay = $now->copy()->startOfDay();
        $endOfDay = $now->copy()->endOfDay();

        $incomesMes = (float) $user->movements()
            ->where('type', 'income')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        if ($incomesMes <= 0) {
            return null;
        }

        $metaAhorro = $incomesMes * (float) $user->savings_mode_pct;
        $techoDiario = ($incomesMes - $metaAhorro) / 30;

        // Gastos totales de hoy (el nuevo movimiento ya está en DB tras commit)
        $gastosHoy = (float) $user->movements()
            ->where('type', 'expense')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->sum('amount');

        $gastosHoyAntes = $gastosHoy - (float) $movement->amount;

        $excesoAnterior = max(0.0, $gastosHoyAntes - $techoDiario);
        $excesoNuevo = max(0.0, $gastosHoy - $techoDiario);
        $fugaIncremental = round($excesoNuevo - $excesoAnterior, 2);

        if ($fugaIncremental <= 0) {
            return null;
        }

        $saldoActual = (float) $user->challenge_savings_balance;
        $nuevoSaldo = max(0.0, round($saldoActual - $fugaIncremental, 2));

        $user->update(['challenge_savings_balance' => $nuevoSaldo]);

        Log::info("Leaky bucket user {$user->id}: fuga={$fugaIncremental}, balance {$saldoActual} -> {$nuevoSaldo}");

        return [
            'fuga_aplicada' => $fugaIncremental,
            'techo_diario' => round($techoDiario, 2),
            'gastos_hoy' => round($gastosHoy, 2),
            'ahorro_protegido_actual' => $nuevoSaldo,
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
                $to = $budget->date_to->format('Y-m-d').' 23:59:59';

                $spent = (float) Movement::where('user_id', $user->id)
                    ->where('type', 'expense')
                    ->whereBetween('created_at', [$from, $to])
                    ->whereHas('tag', fn ($q) => $q->whereIn('name', $linkedTags))
                    ->sum('amount');

                $budgeted = (float) $category->amount;
                $ratio = $budgeted > 0 ? $spent / $budgeted : 0;

                Log::info('Budget impact computed', [
                    'budget_id' => $budget->id,
                    'category' => $category->name,
                    'spent' => $spent,
                    'budgeted' => $budgeted,
                ]);

                return [
                    'budget_id' => $budget->id,
                    'budget_title' => $budget->title,
                    'category' => $category->name,
                    'monto_gastado' => round($spent, 2),
                    'monto_presupuestado' => $budgeted,
                    'porcentaje_progreso' => round($ratio * 100, 1),
                    'estado_color' => $ratio >= 1.0 ? 'red' : ($ratio >= 0.75 ? 'yellow' : 'green'),
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
     * Update a movement.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $request->validate([
            'type' => 'sometimes|required|in:income,expense',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'description' => 'sometimes|nullable|string|max:255',
            'payment_method' => 'sometimes|nullable|in:cash,digital',
            'tag_name' => 'sometimes|nullable|string|max:100',
            'has_invoice' => 'sometimes|nullable|boolean',
            'is_business_expense' => 'sometimes|nullable|boolean',
            'rent_type' => 'sometimes|nullable|in:laboral,honorarios,capital,comercial,otros',
        ]);

        try {
            $user = Auth::user();
            $movement = Movement::where('user_id', $user->id)->findOrFail($id);

            DB::beginTransaction();

            $tagId = $movement->tag_id;
            if ($request->has('tag_name')) {
                if (empty($request->tag_name)) {
                    $tagId = null;
                } else {
                    $tagName = ucfirst(strtolower(trim($request->tag_name)));
                    $tag = Tag::firstOrCreate(['user_id' => $user->id, 'name' => $tagName]);
                    $tagId = $tag->id;
                }
            }

            $newType = $request->input('type', $movement->type);

            $movement->update([
                'type' => $newType,
                'amount' => $request->input('amount', $movement->amount),
                'description' => $request->input('description', $movement->description),
                'payment_method' => $request->input('payment_method', $movement->payment_method),
                'has_invoice' => $request->input('has_invoice', $movement->has_invoice),
                'is_business_expense' => $request->input('is_business_expense', $movement->is_business_expense),
                'rent_type' => $newType === 'income' ? $request->input('rent_type', $movement->rent_type) : null,
                'tag_id' => $tagId,
            ]);

            $movement->load('tag');
            DB::commit();

            $this->invalidateUserSummaryCache($user->id);

            Log::info("Movement {$movement->id} updated by user {$user->id}");

            $budgetImpact = null;
            try {
                $budgetImpact = $this->computeBudgetImpact($user, $movement);
                $this->recalculateSavingsChallenge($user);
            } catch (\Exception $sideEffectEx) {
                Log::warning("Side-effect error after movement {$movement->id} updated: ".$sideEffectEx->getMessage());
            }

            return $this->successResponse([
                'movement' => new MovementResource($movement),
                'budget_impact' => $budgetImpact,
            ], 'Movimiento actualizado');

        } catch (ModelNotFoundException) {
            return $this->errorResponse('Movimiento no encontrado', 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating movement: '.$e->getMessage());

            return $this->errorResponse('Error al actualizar movimiento: '.$this->safeMessage($e));
        }
    }

    /**
     * Delete a movement.
     */
    public function destroy($id): JsonResponse
    {
        try {
            $user = Auth::user();
            $movement = Movement::where('user_id', $user->id)->findOrFail($id);

            $movement->delete();

            $this->invalidateUserSummaryCache($user->id);
            $this->recalculateSavingsChallenge($user);

            Log::info("Movement {$id} deleted by user {$user->id}");

            return $this->successResponse(null, 'Movimiento eliminado');

        } catch (ModelNotFoundException) {
            return $this->errorResponse('Movimiento no encontrado', 404);
        } catch (\Exception $e) {
            Log::error('Error deleting movement: '.$e->getMessage());

            return $this->errorResponse('Error al eliminar movimiento: '.$this->safeMessage($e));
        }
    }

    /**
     * Suggest a batch of movements from a voice daily-summary.
     */
    public function suggestBatchFromVoice(VoiceSuggestionRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            Log::info("User {$user->id} requesting batch voice suggestion: {$request->transcripcion}");

            $result = $this->aiService->suggestBatchFromVoice($request->transcripcion, $user);

            if (empty($result['movements'])) {
                return $this->errorResponse('No se detectaron movimientos en el resumen.', 422);
            }

            return $this->successResponse(['movements' => $result['movements']]);

        } catch (\Exception $e) {
            Log::error('Error processing batch voice suggestion: ' . $e->getMessage());
            return $this->errorResponse('Error en proceso de IA: ' . $this->safeMessage($e));
        }
    }

    /**
     * Store multiple movements from a single batch request (daily summary).
     * Accepts { "movements": [...] } — max 20 items.
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $request->validate([
            'movements'                       => 'required|array|min:1|max:20',
            'movements.*.type'                => 'required|in:income,expense',
            'movements.*.amount'              => 'required|numeric|min:0.01',
            'movements.*.description'         => 'nullable|string|max:255',
            'movements.*.payment_method'      => 'nullable|in:cash,digital',
            'movements.*.tag_name'            => 'nullable|string|max:100',
            'movements.*.has_invoice'         => 'nullable|boolean',
            'movements.*.is_business_expense' => 'nullable|boolean',
            'movements.*.rent_type'           => 'nullable|in:laboral,honorarios,capital,comercial,otros',
        ]);

        try {
            $user    = Auth::user();
            $created = [];

            DB::beginTransaction();

            foreach ($request->movements as $m) {
                $tagId   = null;
                $tagName = trim($m['tag_name'] ?? '');
                if (! empty($tagName)) {
                    $tag   = Tag::firstOrCreate(['user_id' => $user->id, 'name' => ucfirst(strtolower($tagName))]);
                    $tagId = $tag->id;
                }

                $type     = $m['type'];
                $movement = Movement::create([
                    'type'                => $type,
                    'amount'              => $m['amount'],
                    'description'         => $m['description'] ?? 'Movimiento',
                    'payment_method'      => $m['payment_method'] ?? 'digital',
                    'has_invoice'         => $m['has_invoice'] ?? false,
                    'is_business_expense' => $m['is_business_expense'] ?? false,
                    'rent_type'           => $type === 'income' ? ($m['rent_type'] ?? null) : null,
                    'user_id'             => $user->id,
                    'tag_id'              => $tagId,
                    'is_digital'          => ($m['payment_method'] ?? 'digital') === 'digital',
                ]);

                $movement->load('tag');
                $created[] = new MovementResource($movement);
            }

            DB::commit();

            try {
                $this->recalculateSavingsChallenge($user);
            } catch (\Exception $sideEffectEx) {
                Log::warning('Side-effect error after batch store: ' . $sideEffectEx->getMessage());
            }

            $totalIncome   = collect($request->movements)->where('type', 'income')->sum('amount');
            $totalExpenses = collect($request->movements)->where('type', 'expense')->sum('amount');

            Log::info('Batch of ' . count($created) . " movements created by user {$user->id}");

            return $this->createdResponse([
                'movements' => $created,
                'count'     => count($created),
                'balance_summary' => [
                    'total_income'   => (float) $totalIncome,
                    'total_expenses' => (float) $totalExpenses,
                    'net'            => (float) ($totalIncome - $totalExpenses),
                ],
            ], count($created) . ' movimientos registrados');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating batch movements: ' . $e->getMessage());
            return $this->errorResponse('Error al guardar los movimientos: ' . $this->safeMessage($e));
        }
    }

    /**
     * Transcribe uploaded audio with Groq Whisper and return the text.
     * POST /movements/transcribe
     */
    public function transcribeAudio(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|max:10240',
        ]);

        try {
            $user = Auth::user();

            $voiceLock = Cache::lock("voice_suggest_{$user->id}", 3);
            if (! $voiceLock->get()) {
                return response()->json([
                    'status'      => 'error',
                    'message'     => 'Espera un momento antes de intentar de nuevo.',
                    'error_type'  => 'rate_limited',
                    'retry_after' => 3,
                ], 429);
            }

            $text = $this->aiService->transcribeWithWhisper($request->file('audio'));

            if (empty($text)) {
                return $this->errorResponse('No se detectó voz en el audio.', 422);
            }

            Log::info("Whisper transcription for user {$user->id}", ['text' => substr($text, 0, 80)]);

            return $this->successResponse(['text' => $text]);

        } catch (\Exception $e) {
            Log::error('Error transcribing audio: ' . $e->getMessage());
            return $this->errorResponse('Error al procesar el audio: ' . $this->safeMessage($e));
        }
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

            // ── Cooldown anti-doble-tap (2 s por usuario) ─────────────────────
            // Evita que el app Flutter reintente automáticamente en errores
            // transitorios y queme el throttle de 40/min en segundos.
            // Usa el mismo mecanismo de Cache::lock que store() pero con TTL 2s.
            $voiceLock = Cache::lock("voice_suggest_{$user->id}", 2);
            if (! $voiceLock->get()) {
                return response()->json([
                    'status'      => 'error',
                    'message'     => 'Espera un momento antes de intentar de nuevo.',
                    'error_type'  => 'rate_limited',
                    'retry_after' => 2,
                ], 429);
            }

            Log::info("User {$user->id} is requesting voice suggestion for: {$request->transcripcion}");

            $suggestion = $this->aiService->suggestFromVoice(
                $request->transcripcion,
                $user
            );

            Log::info('Voice suggestion generated successfully', ['suggestion' => $suggestion]);

            // ── Distinguir el tipo de error para dar respuestas claras ─────────
            // 'rate_limited'    → Groq está saturado; el usuario no tiene la culpa.
            //                     HTTP 429 para que el cliente muestre un toast
            //                     diferente ("intenta en unos segundos").
            // 'insufficient_data' → la IA no pudo extraer datos de la voz.
            //                     HTTP 422 para que el cliente pida que el usuario
            //                     reformule su mensaje.
            if (($suggestion['error_type'] ?? null) === 'rate_limited') {
                Log::warning("Voice suggestion bloqueada por rate limit para user {$user->id}");

                return $this->errorResponse(
                    'El servicio de IA está temporalmente ocupado. Espera unos segundos e intenta de nuevo.',
                    429
                );
            }

            if (($suggestion['error_type'] ?? null) === 'insufficient_data') {
                Log::warning("Voice suggestion insufficient_data para user {$user->id}: {$request->transcripcion}");

                return $this->errorResponse('No se pudo interpretar el comando de voz.', 422);
            }

            return $this->successResponse(['movement_suggestion' => $suggestion]);

        } catch (\Exception $e) {
            Log::error('Error processing voice suggestion: '.$e->getMessage());

            return $this->errorResponse('Error en proceso de IA: '.$this->safeMessage($e));
        }
    }
}
