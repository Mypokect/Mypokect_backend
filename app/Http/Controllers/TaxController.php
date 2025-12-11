<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class TaxController extends Controller
{
    public function getData() : JsonResponse
    {
        $user = Auth::user();
        
        // 1. FECHAS: AÑO ACTUAL (2025 en adelante)
        $startOfYear = Carbon::now()->startOfYear(); 
        $endOfYear = Carbon::now()->endOfYear();

        // 2. CALCULAR INGRESOS (SOLO LO QUE ENTRÓ)
        // Esto va directo a la casilla de "Ingresos Brutos"
        $totalIngresos = $user->movements()
            ->where('type', 'income')
            ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->sum('amount');

        // 3. CALCULAR DEDUCCIONES DETECTADAS
        $gastosSalud = $user->movements()
            ->where('type', 'expense')
            ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->where(function($q) {
                $q->where('description', 'like', '%salud%')
                  ->orWhere('description', 'like', '%medicina%')
                  ->orWhere('description', 'like', '%eps%')
                  ->orWhere('description', 'like', '%prepagada%');
            })->sum('amount');

        $gastosVivienda = $user->movements()
            ->where('type', 'expense')
            ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->where(function($q) {
                $q->where('description', 'like', '%hipoteca%')
                  ->orWhere('description', 'like', '%interes%')
                  ->orWhere('description', 'like', '%vivienda%');
            })->sum('amount');

        $retenciones = $user->movements()
            ->where('type', 'expense')
            ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->where(function($q) {
                $q->where('description', 'like', '%retencion%')
                  ->orWhere('description', 'like', '%retefuente%');
            })->sum('amount');

        // 4. PATRIMONIO (CORREGIDO: NO CALCULAR AUTOMÁTICAMENTE)
        // Antes restábamos (Ingresos - Gastos), pero eso confundía con el saldo.
        // Lo dejaremos en 0 para obligar al usuario a ingresar sus Bienes Reales (Casa, Carro).
        // Si prefieres que muestre al menos el dinero en caja, descomenta la lógica de abajo.
        
        $patrimonioEstimado = 0; 

        /* 
        // OPCIONAL: Si quisieras sumar solo el efectivo disponible como parte del patrimonio:
        $ingresosHist = $user->movements()->where('type', 'income')->sum('amount');
        $gastosHist = $user->movements()->where('type', 'expense')->sum('amount');
        $saldo = $ingresosHist - $gastosHist;
        $patrimonioEstimado = $saldo > 0 ? $saldo : 0;
        */

        return response()->json([
            'ingresos_totales' => $totalIngresos,
            'deduc_salud' => $gastosSalud,
            'deduc_vivienda' => $gastosVivienda,
            'retenciones' => $retenciones,
            'patrimonio_estimado' => $patrimonioEstimado, // Ahora llegará como 0
            'year' => $startOfYear->year
        ], 200);
    }
}