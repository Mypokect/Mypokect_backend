<?php

namespace App\Http\Controllers\Finance;

use App\Helpers\UvtHelper;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\FiscalProfile;
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

            // ── Beneficio 1%: Deducción por compras con FE + pago digital (todos los usuarios) ──
            $gastosDeduciblesGenerales = (float) $user->movements()
                ->where('type', 'expense')
                ->where('has_invoice', true)
                ->where('payment_method', 'digital')
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
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('amount');
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

            $resultadoPorDefecto = $this->calcularImpuesto(
                ingresosTotales:         $ingresosTotales,
                ingresosNoConstitutivos: $segSocialDefault,
                deducVivienda:           (float) ($profile?->deduc_vivienda ?? 0),
                deducSaludPrep:          (float) ($profile?->deduc_salud    ?? 0),
                numeroDependientes:      (int)   ($profile?->dependientes   ?? 0),
                aportesVoluntarios:      0.0,
                costosGastos:            0.0,
                aplicarRentaExenta25:    true,
                retenciones:             (float) ($profile?->retenciones    ?? 0),
                patrimonio:              (float) ($profile?->patrimonio     ?? 0),
                segSocialParaDisplay:    $segSocialDefault,
                deduccion1pctFE:         $deduccionComprasGenerales * 0.01,
                uvt:                     $uvt,
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
                'ingresos_por_bolsa'    => $ingresosPorBolsa,
                // metadata: topes pre-formateados para que Flutter no necesite saber qué es una UVT
                'metadata' => [
                    'topes' => [
                        'vivienda'                => '$' . number_format(round(1200 * $uvt / 1_000_000, 1), 1, '.', '') . 'M/año',
                        'prepagada'               => '$' . number_format(round(192  * $uvt / 1_000_000, 1), 1, '.', '') . 'M/año',
                        'deduccion_dependiente'   => '$' . number_format(round(72   * $uvt / 1_000_000, 1), 1, '.', '') . 'M/dep.',
                        'obligacion_declarar'     => '$' . number_format(round(1400 * $uvt / 1_000_000, 1), 1, '.', '') . 'M',
                    ],
                    'uvt_valor' => $uvt,
                    'uvt_año'   => $year,
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
                'tipo_contribuyente'  => ['required', 'string', 'in:empleado,independiente,comerciante,rentista'],
                'ingresos_totales'    => ['required', 'numeric', 'min:0'],
                'patrimonio'          => ['nullable', 'numeric', 'min:0'],
                'retenciones'         => ['nullable', 'numeric', 'min:0'],
                'tiene_eps'           => ['nullable', 'boolean'],
                'tiene_pension'       => ['nullable', 'boolean'],
                'tiene_prepagada'     => ['nullable', 'boolean'],
                'tiene_vivienda'      => ['nullable', 'boolean'],
                'eps_anual'           => ['nullable', 'numeric', 'min:0'],
                'pension_anual'       => ['nullable', 'numeric', 'min:0'],
                'prepagada_anual'     => ['nullable', 'numeric', 'min:0'],
                'vivienda_anual'      => ['nullable', 'numeric', 'min:0'],
                'num_dependientes'    => ['nullable', 'integer', 'min:0', 'max:20'],
                'year'                => ['nullable', 'integer', 'min:2020', 'max:2100'],
            ]);

            $year       = (int) ($data['year'] ?? Carbon::now()->year);
            $uvt        = UvtHelper::valorUvt($year);
            $tipo       = $data['tipo_contribuyente'];
            $ingresos   = (float) $data['ingresos_totales'];
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

            // Costos y gastos reales de la actividad (solo independiente / comerciante)
            // Para empleados: 0 — la ley prohíbe esta deducción (Art 107 E.T.)
            $start        = Carbon::create($year)->startOfYear();
            $end          = Carbon::create($year)->endOfYear();
            $costosGastos = 0.0;
            if (in_array($tipo, ['independiente', 'comerciante']) && Schema::hasColumn('movements', 'is_business_expense')) {
                $costosGastos = (float) Auth::user()->movements()
                    ->where('type', 'expense')
                    ->where('is_business_expense', true)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('amount');
            }

            // Deducción 1% compras con FE + digital (aplica a todos los tipos)
            $gastosDeduciblesGenerales = (float) Auth::user()->movements()
                ->where('type', 'expense')
                ->where('has_invoice', true)
                ->where('payment_method', 'digital')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $deduccion1pctFE = round($gastosDeduciblesGenerales * 0.01, 2);

            $resultado = $this->calcularImpuesto(
                ingresosTotales:         $ingresos,
                ingresosNoConstitutivos: $segSocial,
                deducVivienda:           $deducVivienda,
                deducSaludPrep:          $deducSalud,
                numeroDependientes:      $numDep,
                aportesVoluntarios:      0.0,
                costosGastos:            $costosGastos,
                aplicarRentaExenta25:    $aplicarRenta25,
                retenciones:             $retenciones,
                patrimonio:              $patrimonio,
                segSocialParaDisplay:    $segSocial,
                deduccion1pctFE:         $deduccion1pctFE,
                uvt:                     $uvt,
            );

            // Beneficios por compras — Flutter los muestra en la sección Ley 2277
            $resultado['deduccion_compras_generales'] = $deduccion1pctFE;
            $resultado['costos_gastos_actividad']     = $costosGastos;

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

    private function calcularImpuesto(
        float $ingresosTotales,
        float $ingresosNoConstitutivos,   // seg. social (EPS + pensión)
        float $deducVivienda,             // intereses crédito hipotecario / leasing
        float $deducSaludPrep,            // medicina prepagada
        int   $numeroDependientes,
        float $aportesVoluntarios,
        float $costosGastos,              // costos y gastos actividad (independiente/comerciante)
        bool  $aplicarRentaExenta25 = true,
        float $retenciones          = 0.0,
        float $patrimonio           = 0.0,
        float $segSocialParaDisplay = 0.0,
        // ── Beneficios EXTRA (fuera del tope 40%) ──────────────────────────
        float $deduccion1pctFE      = 0.0,  // Art 258 E.T.: 1% compras con FE + digital
        float $uvt                  = 49799.0, // valor UVT del año (default: 2025)
    ): array {
        // ── Constantes legales (en UVT) ───────────────────────────────────
        $LIMITE_VIVIENDA_ANUAL_UVT  = 1200;  // Art 119 E.T.
        $LIMITE_PREPAGADA_ANUAL_UVT = 192;   // Art 387 E.T.
        $DEDUC_DEPENDIENTE_UVT      = 72;    // Art 387 E.T., máx 4 dependientes
        $TOPE_25_LABORAL_UVT        = 790;   // Art 206-10 E.T.
        $TOPE_GLOBAL_40_PCT         = 0.40;  // Art 336 E.T. párrafo 4
        $TOPE_GLOBAL_ANUAL_UVT      = 1340;  // Art 336 E.T. párrafo 4
        $TOPE_OBLIGACION_UVT        = 1400;  // obligación de declarar renta

        // ── A. Renta Líquida (Art 336) ────────────────────────────────────
        // = Ingresos Brutos - Ingresos No Constitutivos (seg. social) - Costos y Gastos actividad
        $ingresosNetos = max(0.0, $ingresosTotales - $ingresosNoConstitutivos - $costosGastos);

        // ── B. Beneficios FUERA del tope 40% (se restan después del tope) ─
        // B.1 Dependientes — Art 387 E.T.: 72 UVT × dep., máx 4
        $numDep                 = min($numeroDependientes, 4);
        $deducDependientesExtra = round($numDep * $DEDUC_DEPENDIENTE_UVT * $uvt);
        // B.2 Deducción 1% FE — Art 258 E.T. (Ley 2277): ya viene como monto absoluto
        $deduc1pctFEAplicada    = max(0.0, $deduccion1pctFE);

        // ── C. Deducciones SUJETAS al tope 40% (Art 336) ─────────────────
        // Incluye: Intereses Vivienda + Prepagada + Renta Exenta 25%
        $dVivienda           = min($deducVivienda,   $LIMITE_VIVIENDA_ANUAL_UVT  * $uvt);
        $dSalud              = min($deducSaludPrep,  $LIMITE_PREPAGADA_ANUAL_UVT * $uvt);
        $subtotalDeducciones = $dVivienda + $dSalud + $aportesVoluntarios;

        // ── D. Renta exenta 25% (Art 206-10) — sujeta al tope 40% ────────
        $rentaExenta25 = 0.0;
        if ($aplicarRentaExenta25) {
            $basePara25    = $ingresosNetos - $subtotalDeducciones;
            $rentaExenta25 = $basePara25 > 0 ? $basePara25 * 0.25 : 0.0;
            $tope25Pesos   = $TOPE_25_LABORAL_UVT * $uvt;
            if ($rentaExenta25 > $tope25Pesos) {
                $rentaExenta25 = $tope25Pesos;
            }
        }

        // ── E. Tope global: 40% de Renta Líquida ó 1.340 UVT (el menor) — Art 336 ──
        $beneficiosSujetosAlTope    = $subtotalDeducciones + $rentaExenta25;
        $limiteGlobal40             = $ingresosNetos * $TOPE_GLOBAL_40_PCT;
        $limiteGlobalUVT            = $TOPE_GLOBAL_ANUAL_UVT * $uvt;
        $limiteFinalBeneficios      = min($limiteGlobal40, $limiteGlobalUVT);
        $beneficiosAplicadosSujetos = min($beneficiosSujetosAlTope, $limiteFinalBeneficios);
        $superoTope                 = $beneficiosSujetosAlTope > $limiteFinalBeneficios;

        // ── F. Base Gravable Final ────────────────────────────────────────
        // = Renta Líquida
        //   - Beneficios del tope 40% (cap aplicado)        [sujetos al tope]
        //   - Deducción dependientes (Art 387)               [fuera del tope]
        //   - Deducción 1% FE compras digitales (Art 258)   [fuera del tope]
        $baseGravable = max(0.0,
            $ingresosNetos
            - $beneficiosAplicadosSujetos
            - $deducDependientesExtra
            - $deduc1pctFEAplicada
        );

        // ── G. Impuesto — Tabla progresiva Art 241 E.T. (Ley 2277) ──────────
        $baseUVT       = $baseGravable / $uvt;
        $impuestoUVT   = $this->applyArt241($baseUVT);
        $impuestoBruto = round($impuestoUVT * $uvt);

        // Obligación de declarar
        $topeDeclararPesos = $TOPE_OBLIGACION_UVT * $uvt;
        $esObligado = $ingresosTotales > $topeDeclararPesos || $patrimonio > (4500 * $uvt);

        // Impuesto neto a pagar (después de retenciones)
        $impuestoAPagar = max(0.0, $impuestoBruto - $retenciones);

        // Status para la UI
        $statusData = $this->buildStatusData($esObligado, $impuestoAPagar, $impuestoBruto, $retenciones);

        // Mensajes explicativos (movidos desde Flutter)
        $mensajes = $this->buildMensajes(
            $esObligado, $impuestoAPagar, $impuestoBruto, $retenciones,
            $superoTope, $baseUVT, $deducDependientesExtra
        );

        // ── Depuración paso a paso (para la "Escalera de Descuentos" en Flutter) ──
        $totalProtegido = $ingresosTotales - $baseGravable;
        $fmtM = fn ($n) => '$' . number_format(round(abs($n) / 1_000_000, 1), 1, '.', '') . 'M';

        $textoResumen = 'Tu base gravable bajó de ' . $fmtM($ingresosTotales) . ' a ' . $fmtM($baseGravable)
            . ' gracias a ' . $fmtM($totalProtegido) . ' en beneficios legales'
            . ($impuestoBruto === 0.0 ? ', lo que reduce tu impuesto a $0.' : ', reduciendo tu impuesto a ' . $fmtM($impuestoBruto) . '.');

        $depuracion = [
            'ingreso_bruto'              => round($ingresosTotales),
            'menos_salud_pension'        => round($ingresosNoConstitutivos),
            'menos_costos_gastos'        => round($costosGastos),
            'subtotal_ingresos_netos'    => round($ingresosNetos),
            // ── Sujetos al tope 40% ──
            'menos_deduccion_vivienda'   => round($dVivienda),
            'menos_deduccion_salud_prep' => round($dSalud),
            'menos_aportes_voluntarios'  => round($aportesVoluntarios),
            'menos_renta_exenta_25'      => round($rentaExenta25),
            'aplico_tope_40'             => $superoTope,
            'ajuste_por_tope_40'         => $superoTope ? round($beneficiosSujetosAlTope - $beneficiosAplicadosSujetos) : 0,
            'beneficios_totales'         => round($beneficiosAplicadosSujetos),
            // ── FUERA del tope 40% (Art 387 + Art 258) ──
            'menos_dependientes'         => round($deducDependientesExtra),
            'menos_deduccion_1pct_fe'    => round($deduc1pctFEAplicada),
            // ── Resultado ──
            'base_gravable_final'        => round($baseGravable),
            'impuesto_resultante'        => $impuestoBruto,
            'total_protegido'            => round($totalProtegido),
            'texto_resumen'              => $textoResumen,
        ];

        return [
            // Campos que los widgets de Flutter necesitan (en snake_case, Flutter mapea)
            'ingresos_brutos'              => round($ingresosTotales),
            'ingresos_netos'               => round($ingresosNetos),
            'seg_social_calculada'         => round($segSocialParaDisplay),
            // ── Sujetos al tope 40% ──
            'deduc_vivienda_aplicada'      => round($dVivienda),
            'deduc_salud_aplicada'         => round($dSalud),
            'aportes_voluntarios'          => round($aportesVoluntarios),
            'subtotal_deducciones'         => round($subtotalDeducciones),
            'renta_exenta_25'              => round($rentaExenta25),
            'beneficios_tope_40'           => round($beneficiosAplicadosSujetos),
            'supero_tope_40'               => $superoTope ? 1 : 0,
            'limite_final_beneficios'      => round($limiteFinalBeneficios),
            // ── FUERA del tope 40% ──
            'deduccion_dependientes_extra' => round($deducDependientesExtra),
            'deduccion_1pct_fe_aplicada'   => round($deduc1pctFEAplicada), // Art 258 E.T.
            // ── Base y resultado ──
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
        ];
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
