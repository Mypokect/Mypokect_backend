<?php

namespace App\Http\Controllers\Finance;

use App\Helpers\UvtHelper;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\FiscalProfile;
use App\Models\Movement;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TaxController extends Controller
{
    use ApiResponse;

    // ── Perfil Fiscal ─────────────────────────────────────────────────────────

    public function getProfile(Request $request): JsonResponse
    {
        $year    = (int) $request->query('year', Carbon::now()->year);
        $profile = Auth::user()->fiscalProfiles()->where('year', $year)->first();

        return $this->successResponse(
            $profile
                ? $profile->only(['year', 'patrimonio', 'dependientes', 'deduc_salud', 'deduc_vivienda', 'retenciones'])
                : ['year' => $year, 'patrimonio' => 0, 'dependientes' => 0,
                   'deduc_salud' => 0, 'deduc_vivienda' => 0, 'retenciones' => 0]
        );
    }

    public function saveProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year'           => ['required', 'integer', 'min:2020', 'max:2100'],
            'patrimonio'     => ['required', 'numeric', 'min:0'],
            'dependientes'   => ['required', 'integer', 'min:0', 'max:20'],
            'deduc_salud'    => ['required', 'numeric', 'min:0'],
            'deduc_vivienda' => ['required', 'numeric', 'min:0'],
            'retenciones'    => ['required', 'numeric', 'min:0'],
        ]);

        $profile = FiscalProfile::updateOrCreate(
            ['user_id' => Auth::id(), 'year' => $data['year']],
            $data
        );

        return $this->successResponse(
            $profile->only(['year', 'patrimonio', 'dependientes', 'deduc_salud', 'deduc_vivienda', 'retenciones']),
            'Perfil fiscal guardado.'
        );
    }

    // ── Datos Pre-llenados + Cálculo por Defecto ──────────────────────────────

    /**
     * Retorna datos consolidados + resultado fiscal ya calculado por el backend.
     * Flutter solo mapea y muestra. Cero aritmética en el cliente.
     */
    public function getData(Request $request): JsonResponse
    {
        try {
            $user    = Auth::user();
            $year    = (int) $request->query('year', Carbon::now()->year);
            $start   = Carbon::create($year)->startOfYear();
            $end     = Carbon::create($year)->endOfYear();
            $uvt     = UvtHelper::valorUvt($year);

            // ── Movimientos del año ───────────────────────────────────────────
            $ingresosTotales = (float) $user->movements()
                ->where('type', 'income')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            // ── Desglose por bolsa de renta (rent_type) ───────────────────────
            $ingresosPorBolsa = ['laboral' => 0.0, 'honorarios' => 0.0, 'capital' => 0.0, 'comercial' => 0.0, 'otros' => 0.0];
            if (Schema::hasColumn('movements', 'rent_type')) {
                $bolsas = $user->movements()
                    ->where('type', 'income')
                    ->whereNotNull('rent_type')
                    ->whereBetween('created_at', [$start, $end])
                    ->selectRaw('rent_type, SUM(amount) as total')
                    ->groupBy('rent_type')
                    ->pluck('total', 'rent_type')
                    ->toArray();
                foreach ($bolsas as $bolsa => $total) {
                    if (array_key_exists($bolsa, $ingresosPorBolsa)) {
                        $ingresosPorBolsa[$bolsa] = (float) $total;
                    }
                }
                // Ingresos sin clasificar van a 'otros' implícitamente en el total
            }

            // ── Desglose heurístico por nombre de Tag (sin tocar la tabla movements) ──
            $ingresosDesglosados = ['laboral' => 0.0, 'honorarios' => 0.0, 'capital' => 0.0, 'comercial' => 0.0, 'otros' => 0.0];
            $keywordsMap = [
                'laboral'    => ['sueldo', 'nómina', 'nomina', 'salario', 'quincena'],
                'honorarios' => ['honorarios', 'servicios', 'freelance'],
                'capital'    => ['arriendo', 'alquiler', 'intereses', 'dividendos'],
                'comercial'  => ['venta', 'negocio', 'local', 'mercancía', 'mercancia'],
            ];
            $incomeMovements = $user->movements()
                ->with('tag')
                ->where('type', 'income')
                ->whereBetween('created_at', [$start, $end])
                ->get();
            $ingresosParaAuditoria = [];
            foreach ($incomeMovements as $mov) {
                $tagName         = strtolower($mov->tag?->name ?? '');
                $clasificado     = false;
                $bolsaMovimiento = 'otros';
                foreach ($keywordsMap as $bolsa => $keywords) {
                    foreach ($keywords as $kw) {
                        if (str_contains($tagName, $kw)) {
                            $ingresosDesglosados[$bolsa] += (float) $mov->amount;
                            $bolsaMovimiento = $bolsa;
                            $clasificado     = true;
                            break 2;
                        }
                    }
                }
                if (! $clasificado) {
                    $ingresosDesglosados['otros'] += (float) $mov->amount;
                }
                $ingresosParaAuditoria[] = [
                    'id'             => $mov->id,
                    'description'    => $mov->description ?? '',
                    'amount'         => (float) $mov->amount,
                    'date'           => $mov->created_at->toDateString(),
                    'bolsa_asignada' => $bolsaMovimiento,
                ];
            }

            $conteoPorBolsa = ['laboral' => 0, 'honorarios' => 0, 'capital' => 0, 'comercial' => 0, 'otros' => 0];
            foreach ($ingresosParaAuditoria as $auditItem) {
                if (array_key_exists($auditItem['bolsa_asignada'], $conteoPorBolsa)) {
                    $conteoPorBolsa[$auditItem['bolsa_asignada']]++;
                }
            }

            $gastoConFe = (float) $user->movements()
                ->where('type', 'expense')
                ->where('has_invoice', true)
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $gastoSinFe = (float) $user->movements()
                ->where('type', 'expense')
                ->where('has_invoice', false)
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $gastoTotal = $gastoConFe + $gastoSinFe;

            // ── Estimaciones cuando no hay datos etiquetados (lógica movida desde Flutter) ─
            $isTaxEstimated = false;
            if ($gastoConFe === 0.0 && $gastoSinFe === 0.0 && $gastoTotal === 0.0) {
                // Sin movimientos etiquetados: estima con proporción 60/40
                $allExpenses = (float) $user->movements()
                    ->where('type', 'expense')
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('amount');
                if ($allExpenses > 0) {
                    $gastoConFe     = round($allExpenses * 0.6, 2);
                    $gastoSinFe     = round($allExpenses * 0.4, 2);
                    $isTaxEstimated = true;
                }
            }

            $ingresoConFe = 0.0;
            if ($ingresosTotales > 0) {
                // Estima ingreso facturado al 70% (sin tracking por has_invoice en income)
                $ingresoConFe   = round($ingresosTotales * 0.7, 2);
                $isTaxEstimated = true;
            }

            // dinero_perdido: gastos sin FE que superan el mínimo de 5 UVT (movido desde Flutter)
            $topeMinimo    = 5 * $uvt;
            $dineroPerdido = $gastoSinFe > $topeMinimo ? $gastoSinFe : 0.0;

            // Estimaciones para TaxMonitorScreen (movido desde Flutter)
            $tarjetasEstimadas  = round($ingresosTotales * 0.3, 2);
            $consumosEstimados  = round($ingresosTotales * 0.5, 2);

            // ── Beneficio 1%: Deducción por compras personales con FE (todos los usuarios) ──
            // Solo compras personales (no is_business_expense) con factura y pago no-efectivo.
            $gastosDeduciblesGenerales = (float) $user->movements()
                ->where('type', 'expense')
                ->where('has_invoice', true)
                ->where('payment_method', '!=', 'cash')
                ->where(function ($q) {
                    $q->where('is_business_expense', false)->orWhereNull('is_business_expense');
                })
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $deduccionComprasGenerales = round($gastosDeduciblesGenerales * 0.01, 2);

            // ── Costos y Gastos de la actividad (independiente / comerciante) ──
            // getData() retorna el total real; recalculate() aplica la regla por tipo.
            // Guard: la columna solo existe después de correr la migración.
            $costosGastosActividad = 0.0;
            if (Schema::hasColumn('movements', 'is_business_expense')) {
                $costosGastosActividad = (float) $user->movements()
                    ->where('type', 'expense')
                    ->where('is_business_expense', true)
                    ->where('has_invoice', true)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('amount');
            }

            // ── Gastos de actividad por bolsa (Simetría de Gastos) ─────────────
            // Totales por bolsa para que Flutter pinte [Ingreso] − [Gasto FE] = [Neto]
            $gastosPorBolsaTotales = ['laboral' => 0.0, 'honorarios' => 0.0, 'capital' => 0.0, 'comercial' => 0.0];
            if (Schema::hasColumn('movements', 'is_business_expense')) {
                $hasRentTypeCol = Schema::hasColumn('movements', 'rent_type');
                $expGastos = $user->movements()
                    ->where('type', 'expense')
                    ->where('is_business_expense', true)
                    ->where('has_invoice', true)
                    ->whereBetween('created_at', [$start, $end])
                    ->get(['amount', 'rent_type']);
                foreach ($expGastos as $eg) {
                    $bDest = ($hasRentTypeCol && ! empty($eg->rent_type)
                              && array_key_exists($eg->rent_type, $gastosPorBolsaTotales))
                        ? $eg->rent_type
                        : 'comercial';
                    $gastosPorBolsaTotales[$bDest] += (float) $eg->amount;
                }
            }

            // Gastos NO deducibles: efectivo O sin factura (oportunidad perdida)
            $gastosNoDeducibles = (float) $user->movements()
                ->where('type', 'expense')
                ->where(function ($q) {
                    $q->where('has_invoice', false)
                      ->orWhere('payment_method', 'cash');
                })
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            // ── Perfil fiscal del usuario ─────────────────────────────────────
            $profile = $user->fiscalProfiles()->where('year', $year)->first();

            // ── Resultado fiscal por defecto (empleado con datos del perfil) ──
            // El backend es el único "cerebro": calcula con defaults para mostrar
            // antes de que el usuario personalice el simulador.
            $segSocialDefault = round($ingresosTotales * 0.08, 2); // 4% EPS + 4% pensión

            // Seg. social para el cálculo por defecto: estimado solo sobre bolsa laboral
            // para no inflar la deducción cuando hay ingresos comerciales/capital.
            $laboralHonorariosDefault = $ingresosPorBolsa['laboral'] + $ingresosPorBolsa['honorarios'];
            $segSocialDefault = $laboralHonorariosDefault > 0
                ? round($laboralHonorariosDefault * 0.08, 2)
                : round($ingresosTotales * 0.08, 2); // fallback si no hay clasificación

            // ── Simetría de Costos: aplica gastos FE por bolsa antes del cálculo ────
            // Capital y Comercial absorben sus propios costos de actividad (Art 107 E.T.)
            // Laboral y Honorarios no tienen costos de actividad directos.
            $ingresoCapitalNeto   = max(0.0, $ingresosPorBolsa['capital']   - ($gastosPorBolsaTotales['capital']    ?? 0.0));
            $ingresoComercialNeto = max(0.0, $ingresosPorBolsa['comercial'] - ($gastosPorBolsaTotales['comercial']  ?? 0.0));

            $resultadoPorDefecto = $this->calcularImpuesto(
                ingresosTotales:         $ingresosTotales,
                ingresosNoConstitutivos: $segSocialDefault,
                deducVivienda:           (float) ($profile?->deduc_vivienda ?? 0),
                deducSaludPrep:          (float) ($profile?->deduc_salud    ?? 0),
                numeroDependientes:      (int)   ($profile?->dependientes   ?? 0),
                aportesVoluntarios:      0.0,
                costosGastos:            0.0,   // Absorbidos por bolsa arriba
                aplicarRentaExenta25:    true,
                retenciones:             (float) ($profile?->retenciones    ?? 0),
                patrimonio:              (float) ($profile?->patrimonio     ?? 0),
                segSocialParaDisplay:    $segSocialDefault,
                deduccion1pctFE:         $deduccionComprasGenerales,
                uvt:                     $uvt,
                ingresosLaboral:         $ingresosPorBolsa['laboral'],
                ingresosHonorarios:      $ingresosPorBolsa['honorarios'],
                ingresosCapital:         $ingresoCapitalNeto,
                ingresosComercial:       $ingresoComercialNeto,
            );

            return $this->successResponse([
                'year'                  => $year,
                'ingresos_totales'      => $ingresosTotales,
                'gasto_con_fe'          => $gastoConFe,
                'gasto_sin_fe'          => $gastoSinFe,
                'alerta_efectivo'       => $gastoSinFe,   // alias de compatibilidad
                'ingreso_con_fe'        => $ingresoConFe,
                'dinero_perdido'        => $dineroPerdido,
                'tarjetas_estimadas'    => $tarjetasEstimadas,
                'consumos_estimados'    => $consumosEstimados,
                'is_tax_estimated'          => $isTaxEstimated,
                'gastos_deducibles_generales'  => $gastosDeduciblesGenerales,
                'deduccion_compras_generales'  => $deduccionComprasGenerales,
                'costos_gastos_actividad'      => $costosGastosActividad,
                'oportunidad_ahorro_perdida'   => $gastosNoDeducibles,
                'patrimonio'            => (float) ($profile?->patrimonio     ?? 0),
                'dependientes'          => (int)   ($profile?->dependientes   ?? 0),
                'deduc_salud'           => (float) ($profile?->deduc_salud    ?? 0),
                'deduc_vivienda'        => (float) ($profile?->deduc_vivienda ?? 0),
                'retenciones'           => (float) ($profile?->retenciones    ?? 0),
                'has_profile'           => $profile !== null,
                'resultado_por_defecto' => $resultadoPorDefecto,
                'ingresos_por_bolsa'      => $ingresosPorBolsa,
                'gastos_por_bolsa'        => $gastosPorBolsaTotales,
                'ingresos_desglosados'    => $ingresosDesglosados,
                'ingresos_para_auditoria' => $ingresosParaAuditoria,
                'utilidad_por_bolsa'      => [
                    'laboral'    => $resultadoPorDefecto['renta_liquida_laboral']    ?? 0,
                    'honorarios' => $resultadoPorDefecto['renta_liquida_honorarios'] ?? 0,
                    'capital'    => $resultadoPorDefecto['renta_liquida_capital']    ?? 0,
                    'comercial'  => $resultadoPorDefecto['renta_liquida_comercial']  ?? 0,
                ],
                'movimientos_ingreso'     => $ingresosParaAuditoria, // alias para el módulo de auditoría
                'movimientos_auditados'   => array_map(fn($m) => array_merge($m, ['bolsa_actual' => $m['bolsa_asignada']]), $ingresosParaAuditoria),
                // metadata: topes pre-formateados para que Flutter no necesite saber qué es una UVT
                'metadata' => [
                    'topes' => [
                        'vivienda'                => '$' . number_format(round(1200 * $uvt / 1_000_000, 1), 1, '.', '') . 'M/año',
                        'prepagada'               => '$' . number_format(round(192  * $uvt / 1_000_000, 1), 1, '.', '') . 'M/año',
                        'deduccion_dependiente'   => '$' . number_format(round(72   * $uvt / 1_000_000, 1), 1, '.', '') . 'M/dep.',
                        'obligacion_declarar'     => '$' . number_format(round(1400 * $uvt / 1_000_000, 1), 1, '.', '') . 'M',
                    ],
                    'uvt_valor'        => $uvt,
                    'uvt_año'          => $year,
                    'conteo_por_bolsa' => $conteoPorBolsa,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Tax getData error: ' . $e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    // ── Simulador Fiscal Interactivo ──────────────────────────────────────────

    /**
     * POST /taxes/recalculate
     *
     * Recibe todos los parámetros del simulador (tipo de contribuyente, deducciones,
     * número de dependientes, etc.) y devuelve el impuesto estimado completo.
     * Flutter ya no sabe qué es una UVT: solo envía datos y muestra resultados.
     */
    public function recalculate(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'tipo_contribuyente'      => ['nullable', 'string', 'in:empleado,independiente,comerciante,rentista'],
                'ingresos_totales'        => ['required', 'numeric', 'min:0'],
                'patrimonio'              => ['nullable', 'numeric', 'min:0'],
                'retenciones'             => ['nullable', 'numeric', 'min:0'],
                'tiene_eps'               => ['nullable', 'boolean'],
                'tiene_pension'           => ['nullable', 'boolean'],
                'tiene_prepagada'         => ['nullable', 'boolean'],
                'tiene_vivienda'          => ['nullable', 'boolean'],
                'eps_anual'               => ['nullable', 'numeric', 'min:0'],
                'pension_anual'           => ['nullable', 'numeric', 'min:0'],
                'prepagada_anual'         => ['nullable', 'numeric', 'min:0'],
                'vivienda_anual'          => ['nullable', 'numeric', 'min:0'],
                'num_dependientes'        => ['nullable', 'integer', 'min:0', 'max:20'],
                'year'                    => ['nullable', 'integer', 'min:2020', 'max:2100'],
                // Desglose por cédula (opcional) — si se envían, la renta exenta 25% solo aplica a laboral+honorarios
                'ingresos_laboral'        => ['nullable', 'numeric', 'min:0'],
                'ingresos_honorarios'     => ['nullable', 'numeric', 'min:0'],
                'ingresos_capital'        => ['nullable', 'numeric', 'min:0'],
                'ingresos_comercial'      => ['nullable', 'numeric', 'min:0'],
                // Auditoría ingresos: reclasificaciones [{'id': 123, 'nueva_bolsa': 'comercial'}, ...]
                'asignaciones_auditoria'              => ['nullable', 'array'],
                'asignaciones_auditoria.*.id'         => ['required_with:asignaciones_auditoria', 'integer'],
                'asignaciones_auditoria.*.nueva_bolsa'=> ['required_with:asignaciones_auditoria', 'string',
                                                          'in:laboral,honorarios,capital,comercial,otros'],
                // Auditoría gastos: asignación de gastos a bolsas [{'gasto_id': 456, 'bolsa_destino': 'capital'}, ...]
                'asignaciones_gastos'                        => ['nullable', 'array'],
                'asignaciones_gastos.*.gasto_id'             => ['required_with:asignaciones_gastos', 'integer'],
                'asignaciones_gastos.*.bolsa_destino'        => ['required_with:asignaciones_gastos', 'string',
                                                                 'in:honorarios,capital,comercial'],
            ]);

            $year       = (int) ($data['year'] ?? Carbon::now()->year);
            $uvt        = UvtHelper::valorUvt($year);
            $ingresos   = (float) $data['ingresos_totales'];

            // Auto-deducción del tipo de contribuyente desde la distribución de bolsas
            $laboral    = (float) ($data['ingresos_laboral']    ?? 0);
            $honorarios = (float) ($data['ingresos_honorarios'] ?? 0);
            $capital    = (float) ($data['ingresos_capital']    ?? 0);
            $comercial  = (float) ($data['ingresos_comercial']  ?? 0);
            if (! empty($data['tipo_contribuyente'])) {
                $tipo = $data['tipo_contribuyente'];
            } else {
                $bolsaMax = max($laboral, $honorarios, $capital, $comercial);
                if ($bolsaMax == 0 || $bolsaMax == $laboral) $tipo = 'empleado';
                elseif ($bolsaMax == $honorarios)            $tipo = 'independiente';
                elseif ($bolsaMax == $comercial)             $tipo = 'comerciante';
                else                                         $tipo = 'rentista';
            }
            $patrimonio = (float) ($data['patrimonio']    ?? 0);
            $retenciones= (float) ($data['retenciones']   ?? 0);
            $numDep     = (int)   ($data['num_dependientes'] ?? 0);

            // Seguridad social: el backend calcula según tipo (movido desde Flutter)
            if ($tipo === 'empleado') {
                $segSocial = round($ingresos * 0.08, 2); // 4% EPS + 4% pensión (empleado)
                $epsAnual     = round($ingresos * 0.04, 2);
                $pensionAnual = round($ingresos * 0.04, 2);
            } else {
                $epsAnual     = ($data['tiene_eps']     ?? false) ? (float) ($data['eps_anual']     ?? 0) : 0.0;
                $pensionAnual = ($data['tiene_pension']  ?? false) ? (float) ($data['pension_anual']  ?? 0) : 0.0;
                $segSocial    = $epsAnual + $pensionAnual;
            }

            $deducSalud   = ($data['tiene_prepagada'] ?? false) ? (float) ($data['prepagada_anual'] ?? 0) : 0.0;
            $deducVivienda= ($data['tiene_vivienda']  ?? false) ? (float) ($data['vivienda_anual']  ?? 0) : 0.0;

            $aplicarRenta25 = in_array($tipo, ['empleado', 'independiente']);

            // Costos y gastos reales de la actividad (no empleados — Art 107 E.T.)
            // Aplica a independiente, comerciante y rentista de capital.
            // Para empleados puros: 0 — la ley prohíbe esta deducción.
            $start        = Carbon::create($year)->startOfYear();
            $end          = Carbon::create($year)->endOfYear();
            $costosGastos = 0.0;
            $costosMovimientosList = [];
            $tieneIngresosNoLaborales = ($honorarios + $capital + $comercial) > 0;
            if ($tieneIngresosNoLaborales && Schema::hasColumn('movements', 'is_business_expense')) {
                $costosMovimientos = Auth::user()->movements()
                    ->where('type', 'expense')
                    ->where('is_business_expense', true)
                    ->where('has_invoice', true)
                    ->whereBetween('created_at', [$start, $end])
                    ->orderByDesc('created_at')
                    ->get(['id', 'description', 'amount', 'created_at']);

                foreach ($costosMovimientos as $cm) {
                    $costosGastos += (float) $cm->amount;
                    $costosMovimientosList[] = [
                        'id'          => $cm->id,
                        'description' => $cm->description ?? '',
                        'amount'      => (float) $cm->amount,
                        'date'        => $cm->created_at->toDateString(),
                    ];
                }
            }

            // Deducción 1% compras personales con FE (todos los tipos — Art 258-1 E.T.)
            // Excluye gastos de actividad (is_business_expense = true) para no duplicar.
            $gastosDeduciblesGenerales = (float) Auth::user()->movements()
                ->where('type', 'expense')
                ->where('has_invoice', true)
                ->where('payment_method', '!=', 'cash')
                ->where(function ($q) {
                    $q->where('is_business_expense', false)->orWhereNull('is_business_expense');
                })
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $deduccion1pctFE = round($gastosDeduciblesGenerales * 0.01, 2);

            // ── Auditoría: reclasificaciones manuales de movimientos reales ────────
            $asignaciones = $data['asignaciones_auditoria'] ?? [];
            $conteoPorBolsaAuditoria = ['laboral' => 0, 'honorarios' => 0, 'capital' => 0, 'comercial' => 0, 'otros' => 0];
            $movimientosAuditados = [];
            if (! empty($asignaciones)) {
                $idMap = collect($asignaciones)->keyBy('id');
                $movimientos = Auth::user()->movements()
                    ->where('type', 'income')
                    ->whereIn('id', $idMap->keys()->all())
                    ->whereBetween('created_at', [$start, $end])
                    ->get(['id', 'amount', 'description', 'created_at']);

                $sumasPorBolsa = ['laboral' => 0.0, 'honorarios' => 0.0, 'capital' => 0.0, 'comercial' => 0.0, 'otros' => 0.0];
                foreach ($movimientos as $mov) {
                    $nuevaBolsa = $idMap[(string) $mov->id]['nueva_bolsa'] ?? 'otros';
                    if (array_key_exists($nuevaBolsa, $sumasPorBolsa)) {
                        $sumasPorBolsa[$nuevaBolsa] += (float) $mov->amount;
                        $conteoPorBolsaAuditoria[$nuevaBolsa]++;
                    }
                    $movimientosAuditados[] = [
                        'id'          => $mov->id,
                        'description' => $mov->description ?? '',
                        'amount'      => (float) $mov->amount,
                        'date'        => $mov->created_at->toDateString(),
                        'bolsa_actual'=> $nuevaBolsa,
                    ];
                }

                // Sobreescribe el desglose con los montos reales reclasificados
                $data['ingresos_laboral']    = $sumasPorBolsa['laboral'];
                $data['ingresos_honorarios'] = $sumasPorBolsa['honorarios'];
                $data['ingresos_capital']    = $sumasPorBolsa['capital'];
                $data['ingresos_comercial']  = $sumasPorBolsa['comercial'];
                // Recalcula total real desde los movimientos auditados
                $ingresos = array_sum($sumasPorBolsa);
            }

            // ── Desglose por cédula (opcional) ──────────────────────────────────────
            $ingresosLaboral    = (float) ($data['ingresos_laboral']    ?? 0);
            $ingresosHonorarios = (float) ($data['ingresos_honorarios'] ?? 0);
            $ingresosCapital    = (float) ($data['ingresos_capital']    ?? 0);
            $ingresosComercial  = (float) ($data['ingresos_comercial']  ?? 0);
            $tieneBolsas = ($ingresosLaboral + $ingresosHonorarios + $ingresosCapital + $ingresosComercial) > 0;
            // Si el usuario envió desglose, la renta exenta 25% se limita a laboral+honorarios (Art 206-10)
            $ingresosSujetosRenta25 = $tieneBolsas ? ($ingresosLaboral + $ingresosHonorarios) : -1.0;

            // ── Distribución de gastos por bolsa (asignaciones_gastos) ──────────────
            // Si el usuario envió asignaciones de gastos, aplica cada gasto a su bolsa
            // antes de llamar a calcularImpuesto (los ingresos de cada bolsa quedan netos).
            $asignacionesGastos = $data['asignaciones_gastos'] ?? [];
            $costosPorBolsaAplicados = ['laboral' => 0.0, 'honorarios' => 0.0, 'capital' => 0.0, 'comercial' => 0.0];

            if (! empty($asignacionesGastos) && $tieneBolsas && Schema::hasColumn('movements', 'is_business_expense')) {
                $idMapGastos = collect($asignacionesGastos)->keyBy('gasto_id');
                $gastosAsignados = Auth::user()->movements()
                    ->where('type', 'expense')
                    ->where('is_business_expense', true)
                    ->where('has_invoice', true)
                    ->whereIn('id', $idMapGastos->keys()->map(fn ($k) => (int) $k)->all())
                    ->whereBetween('created_at', [$start, $end])
                    ->get(['id', 'amount']);

                foreach ($gastosAsignados as $gm) {
                    $bDest = $idMapGastos[(string) $gm->id]['bolsa_destino'] ?? 'comercial';
                    if (array_key_exists($bDest, $costosPorBolsaAplicados)) {
                        $costosPorBolsaAplicados[$bDest] += (float) $gm->amount;
                    }
                }

                // Aplica costos por bolsa reduciendo los ingresos de cada bolsa
                $ingresosHonorarios = max(0.0, $ingresosHonorarios - $costosPorBolsaAplicados['honorarios']);
                $ingresosCapital    = max(0.0, $ingresosCapital    - $costosPorBolsaAplicados['capital']);
                $ingresosComercial  = max(0.0, $ingresosComercial  - $costosPorBolsaAplicados['comercial']);
                // Una vez aplicados por bolsa, no duplicar en calcularImpuesto
                $costosGastos = 0.0;
            }

            $resultado = $this->calcularImpuesto(
                ingresosTotales:            $ingresos,
                ingresosNoConstitutivos:    $segSocial,
                deducVivienda:              $deducVivienda,
                deducSaludPrep:             $deducSalud,
                numeroDependientes:         $numDep,
                aportesVoluntarios:         0.0,
                costosGastos:               $costosGastos,
                aplicarRentaExenta25:       $aplicarRenta25,
                retenciones:                $retenciones,
                patrimonio:                 $patrimonio,
                segSocialParaDisplay:       $segSocial,
                deduccion1pctFE:            $deduccion1pctFE,
                uvt:                        $uvt,
                ingresosSujetosRentaExenta: $ingresosSujetosRenta25,
                ingresosLaboral:            $ingresosLaboral,
                ingresosHonorarios:         $ingresosHonorarios,
                ingresosCapital:            $ingresosCapital,
                ingresosComercial:          $ingresosComercial,
            );

            // Beneficios por compras — Flutter los muestra en la sección Ley 2277
            $resultado['deduccion_compras_generales'] = $deduccion1pctFE;
            $resultado['costos_gastos_actividad']     = $costosGastos;
            $resultado['costos_actividad']            = $costosMovimientosList;
            // costos_no_absorbidos ya viene calculado dentro de calcularImpuesto() — no redefinir
            $resultado['costos_por_bolsa_aplicados']  = $costosPorBolsaAplicados;

            // ── Explicación contextual por perfil ────────────────────────────────────
            $fmtPesos = fn($n) => '$' . number_format(round(abs($n) / 1_000_000, 1), 1, '.', '') . 'M';
            $perfilLabel = match($tipo) {
                'empleado'      => 'Empleado',
                'independiente' => 'Independiente',
                'comerciante'   => 'Comerciante',
                'rentista'      => 'Rentista de Capital',
                default         => ucfirst($tipo),
            };
            $explCostos = $costosGastos > 0
                ? 'descontaste ' . $fmtPesos($costosGastos) . ' en costos de actividad al 100% (Art 107 E.T.)'
                : 'los costos de actividad no aplican para tu perfil (solo para independientes/comerciantes/rentistas)';
            $expl1pct = $deduccion1pctFE > 0
                ? 'más ' . $fmtPesos($deduccion1pctFE) . ' del beneficio 1% por compras personales con factura electrónica (Art 258-1 E.T.)'
                : 'no se encontraron compras personales con factura electrónica para el beneficio 1% FE';
            if (isset($resultado['depuracion_paso_a_paso']) && is_array($resultado['depuracion_paso_a_paso'])) {
                $resultado['depuracion_paso_a_paso']['explicacion_deducciones'] =
                    "Como {$perfilLabel}: {$explCostos}; {$expl1pct}.";
            }

            // Desglose de cédulas (para que Flutter muestre la nota de 25% solo en laboral)
            $resultado['ingresos_desglosados'] = $tieneBolsas ? [
                'laboral'    => $ingresosLaboral,
                'honorarios' => $ingresosHonorarios,
                'capital'    => $ingresosCapital,
                'comercial'  => $ingresosComercial,
            ] : null;
            $resultado['base_renta_exenta_25'] = $tieneBolsas
                ? round($ingresosLaboral + $ingresosHonorarios)
                : null;
            $resultado['conteo_por_bolsa']    = ! empty($asignaciones) ? $conteoPorBolsaAuditoria : null;
            $resultado['movimientos_auditados'] = $movimientosAuditados;
            $resultado['tipo_contribuyente_deducido'] = $tipo;

            // ── Radar Fiscal simulado — valores del request, sin tocar la BD ──────────
            $consumoTarjetas = round($ingresos * 0.3, 2);
            $radarItems = [
                $this->buildRadarItem(
                    label:       'Ingresos Brutos',
                    valorActual: $ingresos,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_INGRESOS_BRUTOS, $year),
                    descripcion: 'Obligación de declarar renta',
                ),
                $this->buildRadarItem(
                    label:       'Consumo Tarjetas',
                    valorActual: $consumoTarjetas,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_INGRESOS_BRUTOS, $year),
                    descripcion: 'Estimado 30% ingresos · DIAN cruza con bancos',
                ),
                $this->buildRadarItem(
                    label:       'Consignaciones',
                    valorActual: $ingresos,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_INGRESOS_BRUTOS, $year),
                    descripcion: 'Depósitos bancarios acumulados en el año',
                ),
                $this->buildRadarItem(
                    label:       'Patrimonio Bruto',
                    valorActual: $patrimonio,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_PATRIMONIO, $year),
                    descripcion: 'Bienes y derechos a 31 dic',
                ),
                $this->buildRadarItem(
                    label:       'Facturación Electrónica',
                    valorActual: $ingresos,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_FACTURACION, $year),
                    descripcion: 'Tope de contratos con e-factura',
                ),
                $this->buildRadarItem(
                    label:       'Régimen Simple',
                    valorActual: $ingresos,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_REGIMEN_SIMPLE, $year),
                    descripcion: 'Umbral máximo para acogerse al SIMPLE',
                ),
            ];

            $excedidos  = count(array_filter($radarItems, fn ($i) => $i['estado'] === 'peligro'));
            $advertidos = count(array_filter($radarItems, fn ($i) => $i['estado'] === 'advertencia'));
            $radarSummary = $excedidos > 0
                ? "⚠️ Superarías $excedidos tope(s) fiscal(es) con estos ingresos."
                : ($advertidos > 0
                    ? "🟡 Te acercarías a $advertidos límite(s). Vigila tus ingresos."
                    : '✅ Dentro de todos los límites con estos ingresos.');

            $resultado['radar_items']   = $radarItems;
            $resultado['radar_summary'] = $radarSummary;

            // Adjunta metadata con topes actualizados para que Flutter los pinte al instante
            $resultado['metadata'] = [
                'topes' => [
                    'vivienda'              => '$' . number_format(round(1200 * $uvt / 1_000_000, 1), 1, '.', '') . 'M/año',
                    'prepagada'             => '$' . number_format(round(192  * $uvt / 1_000_000, 1), 1, '.', '') . 'M/año',
                    'deduccion_dependiente' => '$' . number_format(round(72   * $uvt / 1_000_000, 1), 1, '.', '') . 'M/dep.',
                    'obligacion_declarar'   => '$' . number_format(round(1400 * $uvt / 1_000_000, 1), 1, '.', '') . 'M',
                ],
                'uvt_valor' => $uvt,
                'uvt_año'   => $year,
            ];

            return $this->successResponse($resultado);

        } catch (\Throwable $e) {
            Log::error('Tax recalculate error: ' . $e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    // ── Auditoría Cedular ─────────────────────────────────────────────────────

    /**
     * GET /taxes/cedular-movements
     *
     * Retorna los movimientos de ingreso del año agrupados por bolsa cedular
     * (laboral, honorarios, capital, comercial). Usa rent_type si está disponible,
     * y como fallback clasifica heurísticamente por el nombre del tag.
     */
    public function getCedularMovements(Request $request): JsonResponse
    {
        try {
            $user  = Auth::user();
            $year  = (int) $request->query('year', Carbon::now()->year);
            $start = Carbon::create($year)->startOfYear();
            $end   = Carbon::create($year)->endOfYear();

            $hasRentType = Schema::hasColumn('movements', 'rent_type');

            $keywordsMap = [
                'laboral'    => ['sueldo', 'nómina', 'nomina', 'salario', 'quincena'],
                'honorarios' => ['honorarios', 'servicios', 'freelance'],
                'capital'    => ['arriendo', 'alquiler', 'intereses', 'dividendos'],
                'comercial'  => ['venta', 'negocio', 'local', 'mercancía', 'mercancia'],
            ];

            $incomeMovements = $user->movements()
                ->with('tag')
                ->where('type', 'income')
                ->whereBetween('created_at', [$start, $end])
                ->orderByDesc('created_at')
                ->get();

            $grouped = [
                'laboral'    => ['movimientos' => [], 'total' => 0.0],
                'honorarios' => ['movimientos' => [], 'total' => 0.0],
                'capital'    => ['movimientos' => [], 'total' => 0.0],
                'comercial'  => ['movimientos' => [], 'total' => 0.0],
                'otros'      => ['movimientos' => [], 'total' => 0.0],
            ];

            foreach ($incomeMovements as $mov) {
                // 1. Prioridad: rent_type guardado en BD
                $bolsa = null;
                if ($hasRentType && ! empty($mov->rent_type) && array_key_exists($mov->rent_type, $grouped)) {
                    $bolsa = $mov->rent_type;
                }

                // 2. Fallback heurístico por tag
                if ($bolsa === null) {
                    $tagName = strtolower($mov->tag?->name ?? '');
                    foreach ($keywordsMap as $key => $keywords) {
                        foreach ($keywords as $kw) {
                            if (str_contains($tagName, $kw)) {
                                $bolsa = $key;
                                break 2;
                            }
                        }
                    }
                }

                $bolsa ??= 'otros';

                $entry = [
                    'id'          => $mov->id,
                    'description' => $mov->description ?? '',
                    'amount'      => (float) $mov->amount,
                    'date'        => $mov->created_at->toDateString(),
                    'category'    => $bolsa,
                    'tag'         => $mov->tag?->name,
                ];

                $grouped[$bolsa]['movimientos'][] = $entry;
                $grouped[$bolsa]['total']         += (float) $mov->amount;
            }

            // ── Gastos de actividad agrupados por bolsa (is_business_expense + has_invoice) ──
            $gastosPorBolsa = [
                'laboral'    => ['movimientos' => [], 'total' => 0.0],
                'honorarios' => ['movimientos' => [], 'total' => 0.0],
                'capital'    => ['movimientos' => [], 'total' => 0.0],
                'comercial'  => ['movimientos' => [], 'total' => 0.0],
            ];
            $costosTotal = 0.0;

            if (Schema::hasColumn('movements', 'is_business_expense')) {
                $expenseMovements = $user->movements()
                    ->where('type', 'expense')
                    ->where('is_business_expense', true)
                    ->where('has_invoice', true)
                    ->whereBetween('created_at', [$start, $end])
                    ->orderByDesc('created_at')
                    ->get();

                foreach ($expenseMovements as $mov) {
                    // rent_type doubles as expense bolsa for business expenses; default 'comercial'
                    $bExp = ($hasRentType && ! empty($mov->rent_type)
                             && array_key_exists($mov->rent_type, $gastosPorBolsa))
                        ? $mov->rent_type
                        : 'comercial';

                    $entry = [
                        'id'             => $mov->id,
                        'description'    => $mov->description ?? '',
                        'amount'         => (float) $mov->amount,
                        'date'           => $mov->created_at->toDateString(),
                        'bolsa_asignada' => $bExp,
                    ];
                    $gastosPorBolsa[$bExp]['movimientos'][] = $entry;
                    $gastosPorBolsa[$bExp]['total']         += (float) $mov->amount;
                    $costosTotal                            += (float) $mov->amount;
                }
            }

            // Flat list kept for backward compat
            $costosActividad = array_merge(...array_map(fn ($b) => $b['movimientos'], $gastosPorBolsa));

            $grouped['gastos_por_bolsa']       = $gastosPorBolsa;
            $grouped['costos_actividad']       = $costosActividad;
            $grouped['costos_actividad_total'] = $costosTotal;
            $grouped['costos_no_absorbidos']   = max(0.0, $costosTotal - ($grouped['comercial']['total'] ?? 0.0));

            return $this->successResponse($grouped);

        } catch (\Throwable $e) {
            Log::error('Tax getCedularMovements error: ' . $e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * POST /taxes/move-cedular
     *
     * Reclasifica un movimiento de ingreso a otra bolsa cedular guardando
     * el campo rent_type en la BD. Retorna los datos actualizados de cédulas.
     *
     * Body: { "movement_id": 123, "new_category": "comercial" }
     */
    public function moveCedularMovement(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'movement_id'  => ['required', 'integer'],
                'new_category' => ['required', 'string', 'in:laboral,honorarios,capital,comercial,otros'],
            ]);

            $user       = Auth::user();
            $movementId = (int) $data['movement_id'];
            $newCategory= $data['new_category'];

            // Verificar que el movimiento pertenece al usuario autenticado
            $movement = Movement::where('id', $movementId)
                ->where('user_id', $user->id)
                ->where('type', 'income')
                ->firstOrFail();

            // Persistir la reclasificación si la columna existe
            if (Schema::hasColumn('movements', 'rent_type')) {
                $movement->rent_type = $newCategory;
                $movement->save();
            }

            return $this->successResponse([
                'movement_id'  => $movementId,
                'new_category' => $newCategory,
                'updated'      => true,
            ], 'Movimiento reclasificado correctamente.');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse('Movimiento no encontrado o no pertenece a tu cuenta.', 404);
        } catch (\Throwable $e) {
            Log::error('Tax moveCedularMovement error: ' . $e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    /**
     * POST /taxes/move-gasto-cedular
     *
     * Reclasifica un gasto de actividad a otra bolsa cedular.
     * Usa el campo rent_type para persistir la asignación.
     *
     * Body: { "movement_id": 456, "new_category": "capital" }
     */
    public function moveCedularExpense(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'movement_id'  => ['required', 'integer'],
                'new_category' => ['required', 'string', 'in:laboral,honorarios,capital,comercial'],
            ]);

            $user        = Auth::user();
            $movementId  = (int) $data['movement_id'];
            $newCategory = $data['new_category'];

            $movement = Movement::where('id', $movementId)
                ->where('user_id', $user->id)
                ->where('type', 'expense')
                ->firstOrFail();

            if (Schema::hasColumn('movements', 'rent_type')) {
                $movement->rent_type = $newCategory;
                $movement->save();
            }

            return $this->successResponse([
                'movement_id'  => $movementId,
                'new_category' => $newCategory,
                'updated'      => true,
            ], 'Gasto reclasificado correctamente.');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse('Gasto no encontrado o no pertenece a tu cuenta.', 404);
        } catch (\Throwable $e) {
            Log::error('Tax moveCedularExpense error: ' . $e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    // ── Radar Fiscal ─────────────────────────────────────────────────────────

    public function checkLimits(Request $request): JsonResponse
    {
        try {
            $user  = Auth::user();
            $year  = (int) $request->query('year', Carbon::now()->year);
            $start = Carbon::create($year)->startOfYear();
            $end   = Carbon::create($year)->endOfYear();
            $uvt   = UvtHelper::valorUvt($year);

            $ingresosTotales = (float) $user->movements()
                ->where('type', 'income')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $gastosTotales = (float) $user->movements()
                ->where('type', 'expense')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            // Estima consumo tarjetas y consignaciones desde ingresos (lógica de negocio en el backend)
            $consumoTarjetas  = round($ingresosTotales * 0.3, 2);
            $consignaciones   = $ingresosTotales;

            $profile    = $user->fiscalProfiles()->where('year', $year)->first();
            $patrimonio = (float) ($profile?->patrimonio ?? max(0, $ingresosTotales - $gastosTotales));

            $radarItems = [
                $this->buildRadarItem(
                    label:       'Ingresos Brutos',
                    descripcion: 'Obligación de declarar renta',
                    valorActual: $ingresosTotales,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_INGRESOS_BRUTOS, $year),
                ),
                $this->buildRadarItem(
                    label:       'Consumo Tarjetas',
                    descripcion: 'Estimado 30% ingresos · DIAN cruza con bancos',
                    valorActual: $consumoTarjetas,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_INGRESOS_BRUTOS, $year),
                ),
                $this->buildRadarItem(
                    label:       'Consignaciones',
                    descripcion: 'Depósitos bancarios acumulados en el año',
                    valorActual: $consignaciones,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_INGRESOS_BRUTOS, $year),
                ),
                $this->buildRadarItem(
                    label:       'Patrimonio Bruto',
                    descripcion: 'Bienes y derechos a 31 dic',
                    valorActual: $patrimonio,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_PATRIMONIO, $year),
                ),
                $this->buildRadarItem(
                    label:       'Facturación Electrónica',
                    descripcion: 'Tope de contratos con e-factura',
                    valorActual: $ingresosTotales,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_FACTURACION, $year),
                ),
                $this->buildRadarItem(
                    label:       'Régimen Simple',
                    descripcion: 'Umbral máximo para acogerse al SIMPLE',
                    valorActual: $ingresosTotales,
                    valorTope:   UvtHelper::enPesos(UvtHelper::UVT_REGIMEN_SIMPLE, $year),
                ),
            ];

            // Mantiene el array legacy `data` para compatibilidad con código antiguo
            $alerts = array_map(fn ($item) => [
                'title'          => $item['label'],
                'description'    => $item['descripcion'],
                'current_amount' => $item['valor_actual'],
                'limit_amount'   => $item['valor_tope'],
                'percentage'     => round($item['porcentaje'] * 100, 1),
                'status'         => match($item['estado']) {
                    'peligro'     => 'exceeded',
                    'advertencia' => 'warning',
                    default       => 'safe',
                },
            ], $radarItems);

            return $this->successResponse([
                'year'            => $year,
                'uvt_value'       => $uvt,
                'radar_items'     => $radarItems,
                'data'            => $alerts,
                'summary_message' => $this->generateSummaryMessage($alerts),
            ]);

        } catch (\Throwable $e) {
            Log::error('Tax checkLimits error: ' . $e->getMessage());
            return $this->errorResponse($this->safeMessage($e));
        }
    }

    // ── Motor Fiscal (Art 241 + Art 336 E.T., Ley 2277 de 2022) ─────────────
    // Única fuente de verdad fiscal. Flutter solo mapea y pinta.
    // Cuando se proporcionan ingresosLaboral/Honorarios/Capital/Comercial, activa
    // el cálculo cedular: cada bolsa se depura con sus propias deducciones antes
    // de consolidar la base gravable total.

    private function calcularImpuesto(
        float $ingresosTotales,
        float $ingresosNoConstitutivos,      // seg. social (EPS + pensión)
        float $deducVivienda,                // intereses crédito hipotecario / leasing
        float $deducSaludPrep,               // medicina prepagada
        int   $numeroDependientes,
        float $aportesVoluntarios,
        float $costosGastos,                 // costos y gastos actividad (independiente/comerciante)
        bool  $aplicarRentaExenta25 = true,
        float $retenciones          = 0.0,
        float $patrimonio           = 0.0,
        float $segSocialParaDisplay = 0.0,
        float $deduccion1pctFE      = 0.0,   // Art 258 E.T.: 1% compras con FE + digital
        float $uvt                  = 49799.0,
        float $ingresosSujetosRentaExenta = -1.0, // backward-compat (flat path)
        // ── Cédulas individuales — activan el cálculo segmentado ──────────
        float $ingresosLaboral    = 0.0,
        float $ingresosHonorarios = 0.0,
        float $ingresosCapital    = 0.0,
        float $ingresosComercial  = 0.0,
    ): array {
        // ── Constantes legales (en UVT) ───────────────────────────────────
        $LIMITE_VIVIENDA_ANUAL_UVT  = 1200;  // Art 119 E.T.
        $LIMITE_PREPAGADA_ANUAL_UVT = 192;   // Art 387 E.T.
        $DEDUC_DEPENDIENTE_UVT      = 72;    // Art 387 E.T., máx 4 dependientes
        $TOPE_25_LABORAL_UVT        = 790;   // Art 206-10 E.T.
        $TOPE_GLOBAL_40_PCT         = 0.40;  // Art 336 E.T. párrafo 4
        $TOPE_GLOBAL_ANUAL_UVT      = 1340;  // Art 336 E.T. párrafo 4
        $TOPE_OBLIGACION_UVT        = 1400;  // obligación de declarar renta

        $numDep                 = min($numeroDependientes, 4);
        $deducDependientesExtra = round($numDep * $DEDUC_DEPENDIENTE_UVT * $uvt);
        $deduc1pctFEAplicada    = max(0.0, $deduccion1pctFE);

        $tieneBolsas = ($ingresosLaboral + $ingresosHonorarios + $ingresosCapital + $ingresosComercial) > 0;

        // ══════════════════════════════════════════════════════════════════
        // RAMA A — Cálculo CEDULAR (cuando hay desglose por bolsas)
        // Cada bolsa se depura con sus propias deducciones antes de sumar.
        // ══════════════════════════════════════════════════════════════════
        if ($tieneBolsas) {

            // A-1. BOLSA LABORAL + HONORARIOS (Art 103, 206, 336 E.T.)
            $subtotalLaboralBruto   = $ingresosLaboral + $ingresosHonorarios;
            // Seg. social se aplica SOLO sobre ingresos de trabajo; no puede superar el subtotal
            $segSocialLaboral       = min($ingresosNoConstitutivos, $subtotalLaboralBruto);
            $subtotalLaboralPostSeg = max(0.0, $subtotalLaboralBruto - $segSocialLaboral);
            // Renta exenta 25% (Art 206-10) — ÚNICAMENTE sobre la sub-base laboral.
            // En RAMA A esta variable está físicamente aislada: capital y comercial
            // nunca entran aquí, por lo que el beneficio no puede "fugarse".
            $rentaExenta25 = 0.0;
            if ($subtotalLaboralPostSeg > 0) {
                $tope25Pesos   = $TOPE_25_LABORAL_UVT * $uvt;
                $rentaExenta25 = min($subtotalLaboralPostSeg * 0.25, $tope25Pesos);
            }
            // $baseFinalLaboral = (Laboral+Hon - SegSocial) × 0.75
            $rentaLiquidaLaboral = max(0.0, $subtotalLaboralPostSeg - $rentaExenta25);

            // A-2. BOLSA COMERCIAL (Art 107, 119 E.T.)
            // Costos con factura se restan PRIMERO de comercial; el exceso se traslada a capital
            $comercialPostCostos   = max(0.0, $ingresosComercial - $costosGastos);
            $costosNoAbsorbidos    = max(0.0, $costosGastos - $ingresosComercial);
            // Intereses de vivienda/leasing: el usuario los asigna a esta bolsa
            $dVivienda             = min($deducVivienda, $LIMITE_VIVIENDA_ANUAL_UVT * $uvt);
            $rentaLiquidaComercial = max(0.0, $comercialPostCostos - $dVivienda);

            // A-3. BOLSA CAPITAL — costos no absorbidos por comercial se deducen aquí (Art 107)
            $rentaLiquidaCapital = max(0.0, $ingresosCapital - $costosNoAbsorbidos);

            // A-4. OTROS — ingresos sin bolsa asignada
            $ingresosOtros = max(0.0,
                $ingresosTotales - $subtotalLaboralBruto - $ingresosCapital - $ingresosComercial
            );

            // A-5. BASE CONSOLIDADA = suma de rentas líquidas por bolsa
            $baseConsolidada = $rentaLiquidaLaboral + $rentaLiquidaComercial
                             + $rentaLiquidaCapital + $ingresosOtros;
            $ingresosNetos   = $baseConsolidada;

            // A-6. Prepagada sobre la base consolidada (sujeta al tope 40%)
            //      Vivienda ya fue aplicada en la bolsa comercial — no duplicar
            $dSalud              = min($deducSaludPrep, $LIMITE_PREPAGADA_ANUAL_UVT * $uvt);
            $subtotalDeducciones = $dSalud;
            $beneficiosSujetosAlTope = $subtotalDeducciones;

            // A-7. Tope global 40% Art 336 (sobre base consolidada)
            $limiteGlobal40          = $baseConsolidada * $TOPE_GLOBAL_40_PCT;
            $limiteGlobalUVT         = $TOPE_GLOBAL_ANUAL_UVT * $uvt;
            $limiteFinalBeneficios   = min($limiteGlobal40, $limiteGlobalUVT);
            $beneficiosAplicadosSujetos = min($beneficiosSujetosAlTope, $limiteFinalBeneficios);
            $superoTope              = $beneficiosSujetosAlTope > $limiteFinalBeneficios;

            // A-8. Base Gravable Final
            $baseGravable = max(0.0,
                $baseConsolidada
                - $beneficiosAplicadosSujetos
                - $deducDependientesExtra
                - $deduc1pctFEAplicada
            );

            // Alias para compatibilidad con bloques compartidos inferiores
            $ingresosNoConstitutivos = $segSocialLaboral;

        // ══════════════════════════════════════════════════════════════════
        // RAMA B — Cálculo PLANO (sin desglose — backward compat / getData)
        // ══════════════════════════════════════════════════════════════════
        } else {

            $ingresosNetos       = max(0.0, $ingresosTotales - $ingresosNoConstitutivos - $costosGastos);

            $dVivienda           = min($deducVivienda,  $LIMITE_VIVIENDA_ANUAL_UVT  * $uvt);
            $dSalud              = min($deducSaludPrep, $LIMITE_PREPAGADA_ANUAL_UVT * $uvt);
            $subtotalDeducciones = $dVivienda + $dSalud + $aportesVoluntarios;

            $rentaExenta25 = 0.0;
            if ($aplicarRentaExenta25) {
                $basePara25 = $ingresosNetos - $subtotalDeducciones;
                // Si se proporcionó explícitamente la suma laboral+honorarios, limitar la base.
                // Bloqueo: comercial, capital y otros NUNCA forman parte de la base del 25%.
                if ($ingresosSujetosRentaExenta >= 0) {
                    $basePara25 = min($basePara25, $ingresosSujetosRentaExenta);
                }
                // Seguridad adicional: la base no puede superar los ingresos totales netos
                // (cubre el caso donde ingresosSujetosRentaExenta no fue pasado explícitamente)
                $basePara25    = max(0.0, $basePara25);
                $rentaExenta25 = $basePara25 * 0.25;
                $tope25Pesos   = $TOPE_25_LABORAL_UVT * $uvt;
                if ($rentaExenta25 > $tope25Pesos) { $rentaExenta25 = $tope25Pesos; }
            }

            $beneficiosSujetosAlTope    = $subtotalDeducciones + $rentaExenta25;
            $limiteGlobal40             = $ingresosNetos * $TOPE_GLOBAL_40_PCT;
            $limiteGlobalUVT            = $TOPE_GLOBAL_ANUAL_UVT * $uvt;
            $limiteFinalBeneficios      = min($limiteGlobal40, $limiteGlobalUVT);
            $beneficiosAplicadosSujetos = min($beneficiosSujetosAlTope, $limiteFinalBeneficios);
            $superoTope                 = $beneficiosSujetosAlTope > $limiteFinalBeneficios;

            $baseGravable = max(0.0,
                $ingresosNetos
                - $beneficiosAplicadosSujetos
                - $deducDependientesExtra
                - $deduc1pctFEAplicada
            );

            // Inicializa vars cedurales para que el bloque de memoria no rompa
            $subtotalLaboralBruto   = 0.0;
            $segSocialLaboral       = 0.0;
            $subtotalLaboralPostSeg = 0.0;
            $rentaLiquidaLaboral    = 0.0;
            $comercialPostCostos    = 0.0;
            $rentaLiquidaComercial  = 0.0;
            $rentaLiquidaCapital    = 0.0;
            $costosNoAbsorbidos     = 0.0;
            $ingresosOtros          = 0.0;
            $baseConsolidada        = 0.0;
        }

        // ── Tabla progresiva Art 241 E.T. (Ley 2277) ─────────────────────
        $baseUVT       = $baseGravable / $uvt;
        $impuestoUVT   = $this->applyArt241($baseUVT);
        $impuestoBruto = round($impuestoUVT * $uvt);

        $topeDeclararPesos = $TOPE_OBLIGACION_UVT * $uvt;
        $esObligado        = $ingresosTotales > $topeDeclararPesos || $patrimonio > (4500 * $uvt);
        $impuestoAPagar    = max(0.0, $impuestoBruto - $retenciones);

        $statusData = $this->buildStatusData($esObligado, $impuestoAPagar, $impuestoBruto, $retenciones);
        $mensajes   = $this->buildMensajes($esObligado, $impuestoAPagar, $impuestoBruto, $retenciones,
                          $superoTope, $baseUVT, $deducDependientesExtra);

        // ── Depuración paso a paso ────────────────────────────────────────
        $totalProtegido = $ingresosTotales - $baseGravable;
        $fmtM = fn ($n) => '$' . number_format(round(abs($n) / 1_000_000, 1), 1, '.', '') . 'M';
        $textoResumen   = 'Tu base gravable bajó de ' . $fmtM($ingresosTotales) . ' a ' . $fmtM($baseGravable)
            . ' gracias a ' . $fmtM($totalProtegido) . ' en beneficios legales'
            . ($impuestoBruto === 0.0 ? ', lo que reduce tu impuesto a $0.' : ', reduciendo tu impuesto a ' . $fmtM($impuestoBruto) . '.');

        // Costos totales deducidos de la bolsa no-laboral (para la Hoja de Trabajo)
        $cedularCostosNoLab = $tieneBolsas
            ? round(min($costosGastos, $ingresosComercial) + $costosNoAbsorbidos + $dVivienda)
            : 0;

        $depuracion = [
            'ingreso_bruto'              => round($ingresosTotales),
            'menos_salud_pension'        => round($ingresosNoConstitutivos),
            'menos_costos_gastos'        => $tieneBolsas ? 0 : round($costosGastos),
            'subtotal_ingresos_netos'    => round($ingresosNetos),
            'menos_deduccion_vivienda'   => round($dVivienda),
            'menos_deduccion_salud_prep' => round($dSalud),
            'menos_aportes_voluntarios'  => round($aportesVoluntarios),
            'menos_renta_exenta_25'      => round($rentaExenta25),
            'aplico_tope_40'             => $superoTope,
            'ajuste_por_tope_40'         => $superoTope ? round($beneficiosSujetosAlTope - $beneficiosAplicadosSujetos) : 0,
            'beneficios_totales'         => round($beneficiosAplicadosSujetos),
            'menos_dependientes'         => round($deducDependientesExtra),
            'menos_deduccion_1pct_fe'    => round($deduc1pctFEAplicada),
            'base_gravable_final'        => round($baseGravable),
            'impuesto_resultante'        => $impuestoBruto,
            'total_protegido'            => round($totalProtegido),
            'texto_resumen'              => $textoResumen,
            // ── Secciones cedulares (RAMA A) — Hoja de Trabajo dual ────────────
            // Flutter usa estos campos para mostrar la separación legal de bolsas.
            // Si cedular_activo = false, Flutter cae al modo plano (backward compat).
            'cedular_activo'            => $tieneBolsas,
            'cedular_laboral_bruto'     => $tieneBolsas ? round($subtotalLaboralBruto)      : 0,
            'cedular_seg_social'        => $tieneBolsas ? round($segSocialLaboral)           : 0,
            'cedular_renta25'           => $tieneBolsas ? round($rentaExenta25)              : 0,
            'cedular_base_laboral'      => $tieneBolsas ? round($rentaLiquidaLaboral)        : 0,
            'cedular_no_laboral_bruto'  => $tieneBolsas ? round($ingresosCapital + $ingresosComercial) : 0,
            'cedular_costos_no_lab'     => $cedularCostosNoLab,
            'cedular_base_no_laboral'   => $tieneBolsas ? round($rentaLiquidaComercial + $rentaLiquidaCapital + $ingresosOtros) : 0,
        ];

        // ── Memoria de Cálculo (Hoja de Trabajo DIAN) ─────────────────────
        $tarifaPct   = $this->getTarifaMarginal($baseUVT);
        $tarifaTexto = $baseUVT <= 1090
            ? 'Base ≤ 1.090 UVT — tarifa 0% (exento)'
            : number_format($tarifaPct * 100, 0) . '% sobre el excedente de '
              . number_format($this->getLimiteInferiorUVT($baseUVT), 0) . ' UVT (Art 241)';

        $lineas = [];

        if ($tieneBolsas) {
            // ── Sección Laboral + Honorarios ──────────────────────────────
            $lineas[] = ['concepto' => 'Bolsa Laboral + Honorarios',                                   'monto' => round($subtotalLaboralBruto),     'tipo' => 'suma'];
            if ($segSocialLaboral > 0)
                $lineas[] = ['concepto' => '(-) Seguridad Social — EPS + Pensión',                    'monto' => round($segSocialLaboral),         'tipo' => 'resta'];
            if ($rentaExenta25 > 0) {
                $fmtLab = '$' . number_format(round($subtotalLaboralBruto / 1_000_000, 1), 1, '.', '') . 'M';
                $lineas[] = [
                    'concepto' => '(-) Beneficio legal 25% — aplicado únicamente sobre tus Rentas de Trabajo (' . $fmtLab . ') · Art 206-10',
                    'monto'    => round($rentaExenta25),
                    'tipo'     => 'resta',
                ];
            }
            $lineas[] = ['concepto' => '(=) Renta Laboral Neta',                                       'monto' => round($rentaLiquidaLaboral),      'tipo' => 'subtotal'];

            // ── Sección Comercial ─────────────────────────────────────────
            if ($ingresosComercial > 0 || $costosGastos > 0 || $dVivienda > 0) {
                $costosEnComercial = min($costosGastos, $ingresosComercial);
                $lineas[] = ['concepto' => 'Bolsa Comercial',                                                        'monto' => round($ingresosComercial),     'tipo' => 'suma'];
                if ($costosEnComercial > 0)
                    $lineas[] = ['concepto' => '(-) Costos con Factura — Utilidad en Ventas (Art 107)',               'monto' => round($costosEnComercial),     'tipo' => 'resta'];
                if ($dVivienda > 0)
                    $lineas[] = ['concepto' => '(-) Crédito Hipotecario / Vivienda (Art 119)',                       'monto' => round($dVivienda),             'tipo' => 'resta'];
                $lineas[] = ['concepto' => '(=) Utilidad en Ventas Neta',                                            'monto' => round($rentaLiquidaComercial), 'tipo' => 'subtotal'];
            }

            // ── Sección Capital ───────────────────────────────────────────
            if ($ingresosCapital > 0 || $costosNoAbsorbidos > 0) {
                if ($costosNoAbsorbidos > 0) {
                    $lineas[] = ['concepto' => 'Bolsa Capital',                                                      'monto' => round($ingresosCapital),          'tipo' => 'suma'];
                    $lineas[] = ['concepto' => '(-) Costos con Factura — Utilidad Real (Art 107)',                   'monto' => round($costosNoAbsorbidos),       'tipo' => 'resta'];
                    $lineas[] = ['concepto' => '(=) Utilidad en Capital',                                            'monto' => round($rentaLiquidaCapital),      'tipo' => 'subtotal'];
                } else {
                    $lineas[] = ['concepto' => 'Bolsa Capital (neta)',                                               'monto' => round($rentaLiquidaCapital),      'tipo' => 'suma'];
                }
            }

            $lineas[] = ['concepto' => '(=) Base Consolidada (suma de bolsas)',                        'monto' => round($baseConsolidada),          'tipo' => 'subtotal'];

            // Prepagada sobre base consolidada
            if ($dSalud > 0)
                $lineas[] = ['concepto' => '(-) Medicina Prepagada (Art 387)',                         'monto' => round($dSalud),                   'tipo' => 'resta'];
        } else {
            // ── Cálculo plano (sin desglose) ──────────────────────────────
            $lineas[] = ['concepto' => 'Ingresos Brutos (suma de bolsas)',                             'monto' => round($ingresosTotales),          'tipo' => 'suma'];
            if ($ingresosNoConstitutivos > 0)
                $lineas[] = ['concepto' => '(-) Seguridad Social — EPS + Pensión',                    'monto' => round($ingresosNoConstitutivos),  'tipo' => 'resta'];
            if ($costosGastos > 0)
                $lineas[] = ['concepto' => '(-) Costos y Gastos de la actividad',                     'monto' => round($costosGastos),             'tipo' => 'resta'];
            $lineas[] = ['concepto' => '(=) Renta Líquida Inicial',                                   'monto' => round($ingresosNetos),            'tipo' => 'subtotal'];
            if ($dSalud > 0)
                $lineas[] = ['concepto' => '(-) Medicina Prepagada (Art 387)',                         'monto' => round($dSalud),                   'tipo' => 'resta'];
            if ($dVivienda > 0)
                $lineas[] = ['concepto' => '(-) Crédito Hipotecario / Vivienda (Art 119)',             'monto' => round($dVivienda),                'tipo' => 'resta'];
            if ($aportesVoluntarios > 0)
                $lineas[] = ['concepto' => '(-) Aportes Voluntarios Pensión',                         'monto' => round($aportesVoluntarios),       'tipo' => 'resta'];
            if ($rentaExenta25 > 0)
                $lineas[] = ['concepto' => '(-) Renta Exenta 25% Laboral (Art 206-10)',                'monto' => round($rentaExenta25),            'tipo' => 'resta'];
        }

        // ── Líneas comunes (fuera del tope 40%) ───────────────────────────
        if ($deducDependientesExtra > 0)
            $lineas[] = ['concepto' => '(-) Dependientes a cargo (Art 387)',                           'monto' => round($deducDependientesExtra),   'tipo' => 'resta_extra'];
        if ($deduc1pctFEAplicada > 0)
            $lineas[] = [
                'concepto' => '(-) 1% Compras personales con FE (Art 258-1) — aplica a todos los perfiles',
                'monto'    => round($deduc1pctFEAplicada),
                'tipo'     => 'resta_extra',
            ];
        $lineas[] = ['concepto' => '(=) Base Gravable Final',                                          'monto' => round($baseGravable),             'tipo' => 'subtotal_final'];
        $lineas[] = ['concepto' => 'Impuesto — ' . $tarifaTexto,                                       'monto' => $impuestoBruto,                   'tipo' => 'impuesto'];
        if ($retenciones > 0)
            $lineas[] = ['concepto' => '(-) Retenciones en la Fuente',                                'monto' => round($retenciones),              'tipo' => 'resta'];
        $lineas[] = ['concepto' => '(=) TOTAL A PAGAR',                                               'monto' => round($impuestoAPagar),           'tipo' => 'total'];

        $memoriaCalculo = [
            'lineas'         => $lineas,
            'tarifa_pct'     => $tarifaPct,
            'tarifa_texto'   => $tarifaTexto,
            'alerta_tope_40' => $superoTope ? [
                'monto_solicitado' => round($beneficiosSujetosAlTope),
                'monto_aplicado'   => round($beneficiosAplicadosSujetos),
                'recorte'          => round($beneficiosSujetosAlTope - $beneficiosAplicadosSujetos),
                'texto'            => '⚠️ La ley limitó tus descuentos de '
                    . $fmtM($beneficiosSujetosAlTope) . ' a ' . $fmtM($beneficiosAplicadosSujetos)
                    . ' por superar el 40% de tus ingresos (Art 336 E.T.).',
            ] : null,
        ];

        return [
            'ingresos_brutos'              => round($ingresosTotales),
            'ingresos_netos'               => round($ingresosNetos),
            'seg_social_calculada'         => round($segSocialParaDisplay),
            'deduc_vivienda_aplicada'      => round($dVivienda),
            'deduc_salud_aplicada'         => round($dSalud),
            'aportes_voluntarios'          => round($aportesVoluntarios),
            'subtotal_deducciones'         => round($subtotalDeducciones),
            'renta_exenta_25'              => round($rentaExenta25),
            'beneficios_tope_40'           => round($beneficiosAplicadosSujetos),
            'supero_tope_40'               => $superoTope ? 1 : 0,
            'limite_final_beneficios'      => round($limiteFinalBeneficios),
            'deduccion_dependientes_extra' => round($deducDependientesExtra),
            'deduccion_1pct_fe_aplicada'   => round($deduc1pctFEAplicada),
            'base_gravable'                => round($baseGravable),
            'base_gravable_uvt'            => round($baseUVT, 2),
            'impuesto_bruto'               => $impuestoBruto,
            'es_obligado'                  => $esObligado,
            'impuesto_a_pagar'             => round($impuestoAPagar),
            'retenciones_aplicadas'        => round($retenciones),
            'status_msg'                   => $statusData['msg'],
            'status_color'                 => $statusData['color'],
            'mensajes_explicativos'        => $mensajes,
            'depuracion_paso_a_paso'       => $depuracion,
            'memoria_calculo'              => $memoriaCalculo,
            // ── Utilidades por bolsa — para que Flutter muestre netos por cédula ──
            // Laboral y Honorarios comparten la misma depuración (seg. social + renta exenta 25%).
            // Se distribuye la renta líquida combinada proporcionalmente al ingreso bruto de cada bolsa.
            'costos_no_absorbidos'         => $tieneBolsas ? round($costosNoAbsorbidos) : 0,
            'renta_liquida_laboral'        => $tieneBolsas ? round($rentaLiquidaLaboral * ($subtotalLaboralBruto > 0 ? $ingresosLaboral    / $subtotalLaboralBruto : 0.0)) : 0,
            'renta_liquida_honorarios'     => $tieneBolsas ? round($rentaLiquidaLaboral * ($subtotalLaboralBruto > 0 ? $ingresosHonorarios / $subtotalLaboralBruto : 0.0)) : 0,
            'renta_liquida_capital'        => $tieneBolsas ? round($rentaLiquidaCapital)    : 0,
            'renta_liquida_comercial'      => $tieneBolsas ? round($rentaLiquidaComercial)  : 0,
            'base_consolidada_cedular'     => $tieneBolsas ? round($baseConsolidada)        : 0,
        ];
    }

    /** Tarifa marginal Art 241 para mostrar en la memoria de cálculo */
    private function getTarifaMarginal(float $baseUvt): float
    {
        if ($baseUvt <= 1090)  return 0.0;
        if ($baseUvt <= 1700)  return 0.19;
        if ($baseUvt <= 3400)  return 0.28;
        if ($baseUvt <= 4100)  return 0.33;
        if ($baseUvt <= 8670)  return 0.36;
        if ($baseUvt <= 18970) return 0.38;
        if ($baseUvt <= 31000) return 0.40;
        return 0.42;
    }

    /** Límite inferior del rango UVT para el texto de la tarifa */
    private function getLimiteInferiorUVT(float $baseUvt): float
    {
        if ($baseUvt <= 1090)  return 0;
        if ($baseUvt <= 1700)  return 1090;
        if ($baseUvt <= 3400)  return 1700;
        if ($baseUvt <= 4100)  return 3400;
        if ($baseUvt <= 8670)  return 4100;
        if ($baseUvt <= 18970) return 8670;
        if ($baseUvt <= 31000) return 18970;
        return 31000;
    }

    /** Tabla progresiva Art 241 E.T. — 8 rangos */
    private function applyArt241(float $baseUvt): float
    {
        if ($baseUvt <= 1090)  return 0.0;
        if ($baseUvt <= 1700)  return ($baseUvt - 1090) * 0.19;
        if ($baseUvt <= 3400)  return ($baseUvt - 1700) * 0.28 + 116;
        if ($baseUvt <= 4100)  return ($baseUvt - 3400) * 0.33 + 592;
        if ($baseUvt <= 8670)  return ($baseUvt - 4100) * 0.36 + 823;
        if ($baseUvt <= 18970) return ($baseUvt - 8670) * 0.38 + 2469;
        if ($baseUvt <= 31000) return ($baseUvt - 18970) * 0.40 + 6383;
        return ($baseUvt - 31000) * 0.42 + 11195;
    }

    private function buildStatusData(bool $esObligado, float $imp, float $impBruto, float $retenciones): array
    {
        if (! $esObligado) {
            return ['msg' => 'No estás obligado', 'color' => 'green'];
        }
        if ($imp > 0) {
            return ['msg' => 'Impuesto Estimado', 'color' => 'red'];
        }
        if ($retenciones > $impBruto && $impBruto > 0) {
            return ['msg' => 'Saldo a tu Favor', 'color' => 'blue'];
        }
        return ['msg' => 'Declaras en Ceros', 'color' => 'blue'];
    }

    private function buildMensajes(
        bool  $esObligado,
        float $impAP,
        float $impBruto,
        float $retenciones,
        bool  $superoTope,
        float $baseUVT,
        float $deducDep
    ): array {
        $fmt = fn ($n) => '$' . number_format($n, 0, ',', '.');
        $msgs = [];

        if (! $esObligado) {
            $msgs[] = '✅ No estás obligado a declarar renta este año.';
        } elseif ($impAP > 0) {
            $msgs[] = '⚠️ Impuesto estimado a pagar: ' . $fmt($impAP) . '.';
        } elseif ($retenciones > $impBruto && $impBruto > 0) {
            $msgs[] = '💚 Saldo a tu favor: ' . $fmt($retenciones - $impBruto) . '. Puedes solicitar devolución.';
        } else {
            $msgs[] = '🔵 Declaras en ceros: no debes impuesto adicional.';
        }

        if ($superoTope) {
            $msgs[] = '📌 Se aplicó el tope del 40% a tus beneficios tributarios.';
        }
        if ($baseUVT > 0 && $baseUVT <= 1090) {
            $msgs[] = '✨ Base gravable bajo el umbral mágico de 1.090 UVT. Tarifa: 0%.';
        }
        if ($deducDep > 0) {
            $msgs[] = '👨‍👩‍👧 Deducción por dependientes aplicada: ' . $fmt($deducDep) . '.';
        }

        return $msgs;
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    /**
     * Construye un ítem para el Radar Fiscal con todos los campos precalculados.
     * Flutter solo pinta: porcentaje va directo al LinearProgressIndicator.value.
     */
    private function buildRadarItem(
        string $label,
        float  $valorActual,
        float  $valorTope,
        string $descripcion = '',
    ): array {
        $porcentaje = $valorTope > 0 ? min(1.0, $valorActual / $valorTope) : 0.0;
        $estado     = $porcentaje >= 1.0 ? 'peligro' : ($porcentaje >= 0.8 ? 'advertencia' : 'seguro');
        $restante   = max(0.0, $valorTope - $valorActual);
        $fmt        = fn ($n) => '$' . number_format((int) round($n), 0, ',', '.');

        $mensaje = match ($estado) {
            'peligro'     => '¡Superaste este tope! Estarás obligado a declarar.',
            'advertencia' => 'Cerca del límite. Te faltan ' . $fmt($restante) . '.',
            default       => 'En orden. Te faltan ' . $fmt($restante) . '.',
        };

        return [
            'label'          => $label,
            'descripcion'    => $descripcion,
            'valor_actual'   => round($valorActual, 2),
            'valor_tope'     => round($valorTope, 2),
            'porcentaje'     => round($porcentaje, 4),   // 0.0 – 1.0, listo para la barra
            'estado'         => $estado,                  // 'seguro' | 'advertencia' | 'peligro'
            'mensaje_estado' => $mensaje,
        ];
    }

    private function buildAlert(string $title, float $current, float $limit, string $desc, float $uvt): array
    {
        $percent = ($limit > 0) ? ($current / $limit) : 0;
        $status  = $percent >= 1.0 ? 'exceeded' : ($percent >= 0.8 ? 'warning' : 'safe');

        return [
            'title'          => $title,
            'description'    => $desc,
            'current_amount' => round($current, 2),
            'limit_amount'   => round($limit, 2),
            'limit_uvt'      => $uvt > 0 ? (int) round($limit / $uvt) : null,
            'percentage'     => round($percent * 100, 1),
            'status'         => $status,
        ];
    }

    private function generateSummaryMessage(array $alerts): string
    {
        $exceeded = count(array_filter($alerts, fn ($a) => $a['status'] === 'exceeded'));
        $warning  = count(array_filter($alerts, fn ($a) => $a['status'] === 'warning'));

        if ($exceeded > 0) return "⚠️ Superaste $exceeded tope(s) fiscal(es). Consulta a tu contador.";
        if ($warning  > 0) return "🟡 Te acercas a $warning límite(s). Monitorea tus ingresos.";
        return '✅ Todo en orden para el año ' . (int) Carbon::now()->year . '.';
    }
}
