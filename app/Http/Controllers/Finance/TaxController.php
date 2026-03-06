<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaxController extends Controller
{
    use ApiResponse;

    /**
     * Get tax data.
     *
     * Returns income, deductible expenses (health, housing), withholdings, and cash payment alerts for Colombian tax purposes.
     */
    public function getData(): JsonResponse
    {
        $user = Auth::user();

        // Opcional: Descomentar para filtrar por el año fiscal actual.
        // $startOfYear = Carbon::now()->startOfYear();
        // $endOfYear = Carbon::now()->endOfYear();

        // 1. INGRESOS BRUTOS
        $totalIngresos = $user->movements()
            ->where('type', 'income')
            // ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->sum('amount');

        // 2. GASTOS DEDUCIBLES (Solo bancarizados = 'digital')
        $gastosSalud = $user->movements()
            ->where('type', 'expense')
            ->where('payment_method', 'digital')
            // ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->where(function ($q) {
                $q->where('description', 'like', '%salud%')
                    ->orWhere('description', 'like', '%medicina%')
                    ->orWhere('description', 'like', '%prepagada%')
                    ->orWhere('description', 'like', '%eps%');
            })->sum('amount');

        $gastosVivienda = $user->movements()
            ->where('type', 'expense')
            ->where('payment_method', 'digital')
            // ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->where(function ($q) {
                $q->where('description', 'like', '%hipoteca%')
                    ->orWhere('description', 'like', '%vivienda%')
                    ->orWhere('description', 'like', '%interes%');
            })->sum('amount');

        // 3. ALERTA: GASTOS DEDUCIBLES PAGADOS EN EFECTIVO (cash)
        $alertaEfectivo = $user->movements()
            ->where('type', 'expense')
            ->where('payment_method', 'cash')
            // ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->where(function ($q) {
                $q->where('description', 'like', '%salud%')
                    ->orWhere('description', 'like', '%medicina%')
                    ->orWhere('description', 'like', '%prepagada%')
                    ->orWhere('description', 'like', '%eps%')
                    ->orWhere('description', 'like', '%hipoteca%')
                    ->orWhere('description', 'like', '%vivienda%')
                    ->orWhere('description', 'like', '%interes%');
            })->sum('amount');

        // 4. RETENCIONES (Independiente del método de pago)
        $retenciones = $user->movements()
            ->where('type', 'expense')
            // ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->where(function ($q) {
                $q->where('description', 'like', '%retencion%')
                    ->orWhere('description', 'like', '%retefuente%');
            })->sum('amount');

        // 5. PATRIMONIO (Estimado manual)
        $patrimonioEstimado = 0;

        return $this->successResponse([
            'ingresos_totales' => $totalIngresos,
            'deduc_salud' => $gastosSalud,
            'deduc_vivienda' => $gastosVivienda,
            'retenciones' => $retenciones,
            'patrimonio_estimado' => $patrimonioEstimado,
            'alerta_efectivo' => $alertaEfectivo,
            'year' => Carbon::now()->year,
        ]);
    }

    /**
     * Check tax limits (radar).
     *
     * Compares user's income/expenses against 2026 Colombian tax thresholds (UVT-based) and returns alert status per category.
     */
    public function checkLimits(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // AQUI TAMBIEN QUITAMOS EL FILTRO DE FECHAS TEMPORALMENTE
            $ingresosTotales = $user->movements()->where('type', 'income')->sum('amount');

            // Rentas capital
            $rentasCapital = $user->movements()
                ->where('type', 'income')
                ->where(function ($q) {
                    $q->where('description', 'like', '%arriendo%')
                        ->orWhere('description', 'like', '%interes%')
                        ->orWhere('description', 'like', '%inversion%');
                })->sum('amount');

            // Patrimonio (Solo liquidez en App para alerta)
            $gastosTotales = $user->movements()->where('type', 'expense')->sum('amount');
            $patrimonioLiq = max(0, $ingresosTotales - $gastosTotales);

            // Topes 2026
            $alerts = [];
            $alerts[] = $this->buildAlert('Ingresos Brutos', $ingresosTotales, 587628000, 'Acumulado');
            $alerts[] = $this->buildAlert('Rentas Capital', $rentasCapital, 119518000, 'Arriendos/Inversión');
            $alerts[] = $this->buildAlert('Facturación Electr.', $ingresosTotales, 183309000, 'Tope Contratos');
            $alerts[] = $this->buildAlert('Impuesto Patrimonio', $patrimonioLiq, 3770928000, 'Bienes Externos');
            $alerts[] = $this->buildAlert('Régimen Simple', $ingresosTotales, 5237400000, '100.000 UVT');

            return $this->successResponse([
                'data' => $alerts,
                'summary_message' => $this->generateSummaryMessage($alerts),
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Error: '.$e->getMessage());
        }
    }

    private function buildAlert($title, $current, $limit, $desc)
    {
        $percent = ($limit > 0) ? ($current / $limit) : 0;
        $status = $percent >= 1.0 ? 'exceeded' : ($percent >= 0.8 ? 'warning' : 'safe');

        return [
            'title' => $title, 'description' => $desc,
            'current_amount' => $current, 'limit_amount' => $limit,
            'percentage' => round($percent * 100, 1), 'status' => $status,
        ];
    }

    private function generateSummaryMessage($alerts)
    {
        $exceeded = 0;
        foreach ($alerts as $a) {
            if ($a['status'] === 'exceeded') {
                $exceeded++;
            }
        }
        if ($exceeded > 0) {
            return "⚠️ Superaste $exceeded topes.";
        }

        return '✅ Todo en orden.';
    }
}
