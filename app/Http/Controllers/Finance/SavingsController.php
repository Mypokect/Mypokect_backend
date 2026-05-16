<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
     * Analyze savings capacity and return three pre-calculated plans.
     * Also includes Alcancía con Fuga data (fuga acumulada del mes).
     */
    public function analyze(): JsonResponse
    {
        try {
            $user = Auth::user();

            $startOfMonth = Carbon::now()->startOfMonth();
            $endOfMonth   = Carbon::now()->endOfMonth();

            $incomes  = (float) ($user->movements()
                ->where('type', 'income')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->sum('amount') ?? 0.0);

            $expenses = (float) ($user->movements()
                ->where('type', 'expense')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->sum('amount') ?? 0.0);

            $disponible = $incomes - $expenses;

            $planes = array_map(function (array $cfg) use ($incomes, $disponible) {
                $ahorro   = round($incomes * $cfg['porcentaje'], 2);
                $diario   = round(($disponible - $ahorro) / 30, 2);
                $esViable = $diario >= 0;

                return [
                    'nombre'             => $cfg['nombre'],
                    'emoji'              => $cfg['emoji'],
                    'porcentaje'         => $cfg['porcentaje'],
                    'color_hex'          => $cfg['color_hex'],
                    'ahorro_mensual'     => $ahorro,
                    'presupuesto_diario' => $diario,
                    'es_viable'          => $esViable,
                    'mensaje_motivador'  => $esViable
                        ? $cfg['mensaje_motivador']
                        : '¡Meta Imposible! Baja el modo de ahorro.',
                    'proyecciones' => [
                        '3_meses'  => round($ahorro * 3,  2),
                        '6_meses'  => round($ahorro * 6,  2),
                        '12_meses' => round($ahorro * 12, 2),
                    ],
                ];
            }, self::PLANES_CONFIG);

            $aiResponse = $this->consultarCoachIA($incomes, $expenses);

            // ── Alcancía con Fuga ─────────────────────────────────────────────
            $hasActiveChallenge    = false;
            $savingsPct            = 0.0;
            $ahorroReservadoMes    = 0.0;
            $ahorroProtegidoActual = 0.0;
            $dineroFugadoMes       = 0.0;
            $disponibleParaVivir   = $disponible;

            if (Schema::hasColumn('users', 'has_active_challenge')) {
                $hasActiveChallenge = (bool) $user->has_active_challenge;
                $savingsPct         = (float) ($user->savings_mode_pct ?? 0.0);

                if ($hasActiveChallenge && $savingsPct > 0) {
                    $ahorroReservadoMes = round($incomes * $savingsPct, 2);

                    $ahorroProtegidoActual = Schema::hasColumn('users', 'challenge_savings_balance')
                        ? (float) ($user->challenge_savings_balance ?? $ahorroReservadoMes)
                        : $ahorroReservadoMes;

                    $dineroFugadoMes     = max(0.0, round($ahorroReservadoMes - $ahorroProtegidoActual, 2));
                    $disponibleParaVivir = round($disponible - $ahorroProtegidoActual, 2);
                }
            }

            $porcentajeLogro = $ahorroReservadoMes > 0
                ? min(1.0, round($ahorroProtegidoActual / $ahorroReservadoMes, 4))
                : 0.0;

            return $this->successResponse([
                'ingresos'                => $incomes,
                'gastos'                  => $expenses,
                'planes'                  => $planes,
                'ai_insight'              => $aiResponse,
                'has_active_challenge'    => $hasActiveChallenge,
                'savings_mode_pct'        => $savingsPct,
                'ahorro_reservado_mes'    => $ahorroReservadoMes,
                'ahorro_protegido_actual' => $ahorroProtegidoActual,
                'dinero_fugado_mes'       => $dineroFugadoMes,
                'disponible_para_vivir'   => $disponibleParaVivir,
                'porcentaje_logro'        => $porcentajeLogro,
            ]);

        } catch (\Throwable $e) {
            Log::error('Savings analysis error: ' . $e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * Persist the user's chosen savings mode percentage.
     * Resets challenge_savings_balance to the reserved amount for the current month.
     */
    public function savePlan(Request $request): JsonResponse
    {
        $request->validate([
            'pct' => ['required', 'numeric', 'between:0.01,0.50'],
        ]);

        $pct  = round((float) $request->pct, 4);
        $user = Auth::user();

        $incomesMes = (float) ($user->movements()
            ->where('type', 'income')
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount') ?? 0.0);

        $ahorroReservado = round($incomesMes * $pct, 2);

        $updateData = ['savings_mode_pct' => $pct];

        if (Schema::hasColumn('users', 'has_active_challenge')) {
            $updateData['has_active_challenge'] = true;
        }

        if (Schema::hasColumn('users', 'challenge_savings_balance')) {
            $updateData['challenge_savings_balance'] = $ahorroReservado;
        }

        $user->update($updateData);

        return $this->successResponse([
            'saved'                   => true,
            'savings_mode_pct'        => $pct,
            'has_active_challenge'    => true,
            'ahorro_reservado_mes'    => $ahorroReservado,
            'ahorro_protegido_actual' => $ahorroReservado,
            'dinero_fugado_mes'       => 0.0,
        ], 'Plan de ahorro activado.');
    }

    /**
     * Cancel the active savings challenge and zero out all savings fields.
     */
    public function cancelPlan(): JsonResponse
    {
        $user       = Auth::user();
        $updateData = ['savings_mode_pct' => 0];

        if (Schema::hasColumn('users', 'has_active_challenge')) {
            $updateData['has_active_challenge'] = false;
        }

        if (Schema::hasColumn('users', 'challenge_savings_balance')) {
            $updateData['challenge_savings_balance'] = 0;
        }

        $user->update($updateData);

        return $this->successResponse([
            'cancelled'               => true,
            'has_active_challenge'    => false,
            'savings_mode_pct'        => 0,
            'ahorro_reservado_mes'    => 0.0,
            'ahorro_protegido_actual' => 0.0,
            'dinero_fugado_mes'       => 0.0,
        ], 'Plan de ahorro cancelado.');
    }

    /**
     * Call Groq for a brief, motivational financial insight.
     */
    private function consultarCoachIA(float $ingresos, float $gastos): array
    {
        $apiKey = config('services.groq.key');
        $pct    = $ingresos > 0 ? round(($gastos / $ingresos) * 100) : 100;
        $color  = $gastos > $ingresos ? 'red' : ($pct > 90 ? 'orange' : 'green');
        $alerta = $pct > 90;

        $prompt = "Coach financiero. Ingresos: $ingresos, Gastos: $gastos ({$pct}%). "
            . 'Responde SOLO JSON válido: {"titulo":"<máx 6 palabras>","mensaje":"<2 oraciones cortas motivadoras en español>",'
            . '"alerta":' . ($alerta ? 'true' : 'false') . ',"color":"' . $color . '"}';

        foreach (['llama-3.1-8b-instant', 'gemma2-9b-it'] as $modelo) {
            try {
                $response = Http::timeout(3)->withHeaders([
                    'Authorization' => "Bearer $apiKey",
                    'Content-Type'  => 'application/json',
                ])->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model'           => $modelo,
                    'messages'        => [['role' => 'user', 'content' => $prompt]],
                    'temperature'     => 0.3,
                    'max_tokens'      => 120,
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
            'titulo'  => 'Análisis Financiero',
            'mensaje' => 'Basado en tus números, aquí está tu capacidad real de ahorro. ¡Tú puedes hacerlo!',
            'alerta'  => $alerta,
            'color'   => $color,
        ];
    }
}
