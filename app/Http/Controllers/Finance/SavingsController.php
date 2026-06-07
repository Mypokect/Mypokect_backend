<?php

namespace App\Http\Controllers\Finance;

use App\Helpers\SchemaCache;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\GoalContribution;
use App\Models\SavingGoal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SavingsController extends Controller
{
    use ApiResponse;

    private const PLANES_CONFIG = [
        ['nombre' => 'Chill',   'emoji' => '🥳', 'porcentaje' => 0.10, 'color_hex' => '29B6F6',
            'mensaje_motivador' => 'Vas seguro, pero a paso lento. ¡No te detengas! 🐢'],
        ['nombre' => 'Enfoque', 'emoji' => '😎', 'porcentaje' => 0.20, 'color_hex' => '006B52',
            'mensaje_motivador' => '¡Este es el punto dulce! Estás construyendo riqueza real. 💎'],
        ['nombre' => 'Bestia',  'emoji' => '🔥', 'porcentaje' => 0.30, 'color_hex' => 'FF7043',
            'mensaje_motivador' => '¡Nivel leyenda! Tu "yo" del futuro te va a agradecer este esfuerzo. 👑'],
    ];

    /**
     * Analyze savings capacity.
     *
     * Optional GET param: valor_ahorro_deseado (amount in COP).
     * When provided, returns custom_analysis with survival budget, risk flag,
     * and time-to-goal calculation for the user's first active SavingGoal.
     */
    public function analyze(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $valorAhorroDeseado = (float) ($request->query('valor_ahorro_deseado') ?? 0);

            $startOfMonth = Carbon::now()->startOfMonth();
            $endOfMonth = Carbon::now()->endOfMonth();

            $incomes = (float) ($user->movements()
                ->where('type', 'income')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->sum('amount') ?? 0.0);

            $expenses = (float) ($user->movements()
                ->where('type', 'expense')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->sum('amount') ?? 0.0);

            $disponible = $incomes - $expenses;
            $gastoPromedioDiario = $expenses > 0 ? round($expenses / 30, 2) : 0.0;

            // Days of current month for countdown
            $now              = Carbon::now();
            $diaActual        = $now->day;
            $diasDelMes       = $now->daysInMonth;
            $diasRestantesMes = $diasDelMes - $diaActual + 1;

            // ── Three pre-configured plans (backward compat) ─────────────────
            $planes = array_map(function (array $cfg) use ($incomes, $disponible) {
                $ahorro = round($incomes * $cfg['porcentaje'], 2);
                $diario = round(($disponible - $ahorro) / 30, 2);
                $esViable = $diario >= 0;

                return [
                    'nombre' => $cfg['nombre'],
                    'emoji' => $cfg['emoji'],
                    'porcentaje' => $cfg['porcentaje'],
                    'color_hex' => $cfg['color_hex'],
                    'ahorro_mensual' => $ahorro,
                    'presupuesto_diario' => $diario,
                    'es_viable' => $esViable,
                    'mensaje_motivador' => $esViable
                        ? $cfg['mensaje_motivador']
                        : '¡Meta Imposible! Baja el modo de ahorro.',
                    'proyecciones' => [
                        '3_meses' => round($ahorro * 3, 2),
                        '6_meses' => round($ahorro * 6, 2),
                        '12_meses' => round($ahorro * 12, 2),
                    ],
                ];
            }, self::PLANES_CONFIG);

            // ── Custom analysis for a specific money amount ───────────────────
            $customAnalysis = null;
            if ($valorAhorroDeseado > 0) {
                $presupuestoDiarioRestante = round(($incomes - $expenses - $valorAhorroDeseado) / 30, 2);
                $riesgoIncumplimiento = $presupuestoDiarioRestante < $gastoPromedioDiario;

                $mesesParaMeta = null;
                $metaNombre = null;
                $metaTotal = null;
                $metaRestante = null;

                $goal = SavingGoal::where('user_id', $user->id)
                    ->whereIn('status', ['active', 'pending', 'en_progreso', 'in_progress'])
                    ->orderBy('created_at', 'desc')
                    ->first();

                if (! $goal) {
                    $goal = SavingGoal::where('user_id', $user->id)
                        ->whereNull('deleted_at')
                        ->orderBy('created_at', 'desc')
                        ->first();
                }

                if ($goal) {
                    $metaTotal = (float) $goal->target_amount;
                    $savedAmount = (float) $goal->contributions()->sum('amount');
                    $metaRestante = max(0.0, $metaTotal - $savedAmount);
                    $mesesParaMeta = $valorAhorroDeseado > 0
                        ? (int) ceil($metaRestante / $valorAhorroDeseado)
                        : null;
                    $metaNombre = $goal->name;
                }

                $customAnalysis = [
                    'valor_ahorro_deseado' => $valorAhorroDeseado,
                    'presupuesto_diario_restante' => $presupuestoDiarioRestante,
                    'gasto_promedio_diario'       => $gastoPromedioDiario,
                    'riesgo_incumplimiento'       => $riesgoIncumplimiento,
                    'es_viable'                   => $presupuestoDiarioRestante >= 0,
                    'meses_para_meta'             => $mesesParaMeta,
                    'meta_nombre'                 => $metaNombre,
                    'meta_id'                     => $goal?->id,
                    'meta_total'                  => $metaTotal,
                    'meta_restante'               => $metaRestante,
                ];
            }

            $aiResponse = $this->consultarCoachIA($incomes, $expenses);

            // ── Alcancía con Fuga ─────────────────────────────────────────────
            $hasActiveChallenge = false;
            $savingsPct = 0.0;
            $savingsAmount = 0.0;
            $ahorroReservadoMes = 0.0;
            $ahorroProtegidoActual = 0.0;
            $dineroFugadoMes = 0.0;
            $disponibleParaVivir = $disponible;

            if (SchemaCache::hasColumn('users', 'has_active_challenge')) {
                $hasActiveChallenge = (bool) $user->has_active_challenge;
                $savingsPct = (float) ($user->savings_mode_pct ?? 0.0);
                $savingsAmount = (float) ($user->savings_mode_amount ?? 0.0);

                if ($hasActiveChallenge) {
                    // Use the saved money amount directly if available; fall back to pct
                    $ahorroReservadoMes = $savingsAmount > 0
                        ? $savingsAmount
                        : round($incomes * $savingsPct, 2);

                    $ahorroProtegidoActual = SchemaCache::hasColumn('users', 'challenge_savings_balance')
                        ? (float) ($user->challenge_savings_balance ?? $ahorroReservadoMes)
                        : $ahorroReservadoMes;

                    $dineroFugadoMes = max(0.0, round($ahorroReservadoMes - $ahorroProtegidoActual, 2));
                    $disponibleParaVivir = round($disponible - $ahorroProtegidoActual, 2);
                }
            }

            $porcentajeLogro = $ahorroReservadoMes > 0
                ? min(1.0, round($ahorroProtegidoActual / $ahorroReservadoMes, 4))
                : 0.0;

            // Auto-fill custom_analysis when challenge is active but no amount was requested
            if ($customAnalysis === null && $hasActiveChallenge && $savingsAmount > 0) {
                $presupDiario = round(($incomes - $expenses - $savingsAmount) / max(1, $diasRestantesMes), 2);
                $riesgoAuto   = $presupDiario < $gastoPromedioDiario;

                $goalAuto = SavingGoal::where('user_id', $user->id)
                    ->whereIn('status', ['active', 'pending', 'en_progreso', 'in_progress'])
                    ->orderBy('created_at', 'desc')
                    ->first();
                if (! $goalAuto) {
                    $goalAuto = SavingGoal::where('user_id', $user->id)
                        ->whereNull('deleted_at')
                        ->orderBy('created_at', 'desc')
                        ->first();
                }

                $autoMesesParaMeta = null;
                $autoMetaNombre    = null;
                $autoMetaId        = null;
                $autoMetaTotal     = null;
                $autoMetaRestante  = null;

                if ($goalAuto) {
                    $autoMetaTotal    = (float) $goalAuto->target_amount;
                    $autoSaved        = (float) $goalAuto->contributions()->sum('amount');
                    $autoMetaRestante = max(0.0, $autoMetaTotal - $autoSaved);
                    $autoMesesParaMeta = $savingsAmount > 0
                        ? (int) ceil($autoMetaRestante / $savingsAmount)
                        : null;
                    $autoMetaNombre = $goalAuto->name;
                    $autoMetaId     = $goalAuto->id;
                }

                $customAnalysis = [
                    'valor_ahorro_deseado'        => $savingsAmount,
                    'presupuesto_diario_restante' => $presupDiario,
                    'gasto_promedio_diario'       => $gastoPromedioDiario,
                    'riesgo_incumplimiento'       => $riesgoAuto,
                    'es_viable'                   => $presupDiario >= 0,
                    'meses_para_meta'             => $autoMesesParaMeta,
                    'meta_nombre'                 => $autoMetaNombre,
                    'meta_id'                     => $autoMetaId,
                    'meta_total'                  => $autoMetaTotal,
                    'meta_restante'               => $autoMetaRestante,
                ];
            }

            // ── Detect existing savings registration this month ────────────────
            $ahorroYaRegistrado = false;
            $montoRegistrado    = 0.0;
            $tipoRegistro       = null;

            // Check for expense movement tagged "Ahorro"
            $movimientoAhorro = $user->movements()
                ->where('type', 'expense')
                ->whereHas('tag', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['ahorro']))
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->orderByDesc('amount')
                ->first();

            // Check for goal contribution this month (if linked goal)
            $contribucionMeta = null;
            $metaIdParaCheck  = $customAnalysis['meta_id'] ?? null;
            if ($metaIdParaCheck) {
                $contribucionMeta = GoalContribution::where('user_id', $user->id)
                    ->where('goal_id', $metaIdParaCheck)
                    ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                    ->orderByDesc('created_at')
                    ->first();
            }

            if ($contribucionMeta) {
                $ahorroYaRegistrado = true;
                $montoRegistrado    = (float) $contribucionMeta->amount;
                $tipoRegistro       = 'contribucion';
            } elseif ($movimientoAhorro) {
                $ahorroYaRegistrado = true;
                $montoRegistrado    = (float) $movimientoAhorro->amount;
                $tipoRegistro       = 'movimiento';
            }

            // Cumulative savings split: previous months + this month
            // Sources: goal_contributions AND expense movements tagged "Ahorro"
            $contribucionesMesesAnt = (float) GoalContribution::where('user_id', $user->id)
                ->where('created_at', '<', $startOfMonth)
                ->sum('amount');

            $movimientosAhorroAnt = (float) $user->movements()
                ->where('type', 'expense')
                ->whereHas('tag', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['ahorro']))
                ->where('created_at', '<', $startOfMonth)
                ->sum('amount');

            $ahorroMesesAnteriores = $contribucionesMesesAnt + $movimientosAhorroAnt;

            $contribucionesMesActual = (float) GoalContribution::where('user_id', $user->id)
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            $movimientosAhorroMes = (float) $user->movements()
                ->where('type', 'expense')
                ->whereHas('tag', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['ahorro']))
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            $ahorroContribucionMes = $contribucionesMesActual + $movimientosAhorroMes;
            $ahorroProtegidoTotal  = $ahorroMesesAnteriores + $ahorroContribucionMes;

            return $this->successResponse([
                'ingresos' => $incomes,
                'gastos' => $expenses,
                'capacidad_neta' => max(0.0, $disponible),
                'gasto_promedio_diario' => $gastoPromedioDiario,
                'planes' => $planes,
                'ai_insight' => $aiResponse,
                'custom_analysis' => $customAnalysis,
                'has_active_challenge' => $hasActiveChallenge,
                'savings_mode_pct' => $savingsPct,
                'savings_mode_amount' => $savingsAmount,
                'ahorro_reservado_mes' => $ahorroReservadoMes,
                'ahorro_protegido_actual'   => $ahorroProtegidoActual,
                'ahorro_protegido_total'    => $ahorroProtegidoTotal,
                'ahorro_meses_anteriores'   => $ahorroMesesAnteriores,
                'ahorro_contribucion_mes'   => $ahorroContribucionMes,
                'dinero_fugado_mes'       => $dineroFugadoMes,
                'disponible_para_vivir'   => $disponibleParaVivir,
                'porcentaje_logro'        => $porcentajeLogro,
                'dia_del_mes'             => $diaActual,
                'dias_del_mes'            => $diasDelMes,
                'dias_restantes_mes'      => $diasRestantesMes,
                'ahorro_ya_registrado'    => $ahorroYaRegistrado,
                'monto_registrado'        => $montoRegistrado,
                'tipo_registro'           => $tipoRegistro,
            ]);

        } catch (\Throwable $e) {
            Log::error('Savings analysis error: '.$e->getMessage());

            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Persist the user's chosen savings amount (in COP).
     * Also derives and stores the equivalent pct for backward compat.
     */
    public function savePlan(Request $request): JsonResponse
    {
        $request->validate([
            'monto' => ['required', 'numeric', 'min:0'],
        ]);

        $monto = round((float) $request->monto, 2);
        $user = Auth::user();

        $incomesMes = (float) ($user->movements()
            ->where('type', 'income')
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount') ?? 0.0);

        $pct = ($incomesMes > 0 && $monto > 0)
            ? min(0.9999, round($monto / $incomesMes, 4))
            : 0.0;

        $updateData = ['savings_mode_pct' => $pct];

        if (SchemaCache::hasColumn('users', 'savings_mode_amount')) {
            $updateData['savings_mode_amount'] = $monto;
        }

        if (SchemaCache::hasColumn('users', 'has_active_challenge')) {
            $updateData['has_active_challenge'] = true;
        }

        if (SchemaCache::hasColumn('users', 'challenge_savings_balance')) {
            $updateData['challenge_savings_balance'] = $monto;
        }

        $user->update($updateData);

        return $this->successResponse([
            'saved' => true,
            'savings_mode_amount' => $monto,
            'savings_mode_pct' => $pct,
            'has_active_challenge' => true,
            'ahorro_reservado_mes' => $monto,
            'ahorro_protegido_actual' => $monto,
            'dinero_fugado_mes' => 0.0,
        ], 'Plan de ahorro activado.');
    }

    /**
     * Cancel the active savings challenge.
     */
    public function cancelPlan(): JsonResponse
    {
        $user = Auth::user();
        $updateData = ['savings_mode_pct' => 0];

        if (SchemaCache::hasColumn('users', 'savings_mode_amount')) {
            $updateData['savings_mode_amount'] = 0;
        }

        if (SchemaCache::hasColumn('users', 'has_active_challenge')) {
            $updateData['has_active_challenge'] = false;
        }

        if (SchemaCache::hasColumn('users', 'challenge_savings_balance')) {
            $updateData['challenge_savings_balance'] = 0;
        }

        $user->update($updateData);

        return $this->successResponse([
            'cancelled' => true,
            'has_active_challenge' => false,
            'savings_mode_pct' => 0,
            'savings_mode_amount' => 0.0,
            'ahorro_reservado_mes' => 0.0,
            'ahorro_protegido_actual' => 0.0,
            'dinero_fugado_mes' => 0.0,
        ], 'Plan de ahorro cancelado.');
    }

    /**
     * Call Groq for a brief, motivational financial insight.
     */
    private function consultarCoachIA(float $ingresos, float $gastos): array
    {
        $apiKey = config('services.groq.key');
        $pct = $ingresos > 0 ? round(($gastos / $ingresos) * 100) : 100;
        $color = $gastos > $ingresos ? 'red' : ($pct > 90 ? 'orange' : 'green');
        $alerta = $pct > 90;

        $prompt = "Coach financiero. Ingresos: $ingresos, Gastos: $gastos ({$pct}%). "
            .'Responde SOLO JSON válido: {"titulo":"<máx 6 palabras>","mensaje":"<2 oraciones cortas motivadoras en español>",'
            .'"alerta":'.($alerta ? 'true' : 'false').',"color":"'.$color.'"}';

        foreach (['llama-3.1-8b-instant', 'gemma2-9b-it'] as $modelo) {
            try {
                $response = Http::timeout(3)->withHeaders([
                    'Authorization' => "Bearer $apiKey",
                    'Content-Type' => 'application/json',
                ])->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $modelo,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'temperature' => 0.3,
                    'max_tokens' => 120,
                    'response_format' => ['type' => 'json_object'],
                ]);

                if ($response->successful()) {
                    $decoded = json_decode($response['choices'][0]['message']['content'], true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            } catch (\Exception) {
            }
        }

        return [
            'titulo' => 'Análisis Financiero',
            'mensaje' => 'Basado en tus números, aquí está tu capacidad real de ahorro. ¡Tú puedes hacerlo!',
            'alerta' => $alerta,
            'color' => $color,
        ];
    }
}
