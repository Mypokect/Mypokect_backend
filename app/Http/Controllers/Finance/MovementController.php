<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Movement\CreateMovementRequest;
use App\Http\Requests\Movement\VoiceSuggestionRequest;
use App\Http\Resources\MovementResource;
use App\Http\Traits\ApiResponse;
use App\Models\Movement;
use App\Models\Tag;
use App\Services\MovementAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            Log::info("User {$user->id} is requesting movements list.");

            $movements = $user->movements()
                ->with('tag')
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->successResponse(MovementResource::collection($movements));

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
                'has_invoice'         => $request->has_invoice ?? false,
                'is_business_expense' => $request->is_business_expense ?? false,
                'user_id' => $user->id,
                'tag_id' => $tagId,
            ]);

            // Load tag relationship for response
            $movement->load('tag');

            DB::commit();

            Log::info("Movement {$movement->id} created successfully by user {$user->id}");

            // ── Alcancía con Fuga (Ahorro Reactivo) ───────────────────────────
            $leakInfo = $this->applyLeakyBucket($user, $movement);

            $responseData = new MovementResource($movement);

            if ($leakInfo !== null) {
                return $this->createdResponse([
                    'movement'  => $responseData,
                    'leak_info' => $leakInfo,
                ], 'Movimiento creado');
            }

            return $this->createdResponse($responseData, 'Movimiento creado');

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

            return $this->successResponse(['movement_suggestion' => $suggestion]);

        } catch (\Exception $e) {
            Log::error('Error processing voice suggestion: '.$e->getMessage());

            return $this->errorResponse('Error en proceso de IA: ' . $this->safeMessage($e));
        }
    }
}
