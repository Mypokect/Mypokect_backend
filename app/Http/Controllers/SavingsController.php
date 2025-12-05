<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class SavingsController extends Controller
{
    /**
     * Analiza las finanzas del usuario y genera recomendaciones.
     */
    public function analyze()
    {
        $user = Auth::user();

        // 1. Definir el periodo de análisis (Ej: Este mes actual)
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        // 2. Obtener movimientos reales de la BD
        $incomes = $user->movements()
            ->where('type', 'income')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $expenses = $user->movements()
            ->where('type', 'expense')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        // 3. Calcular flujo de caja
        $balance = $incomes - $expenses;
        
        // 4. Lógica del Asistente (Regla 50/30/20 simplificada)
        $idealSavings = $incomes * 0.20; // Meta ideal: 20%
        
        $status = '';
        $color = ''; // Para el frontend (hex code o nombre)
        $message = '';
        $alert = null;
        $recommendedMonthly = 0;

        if ($incomes == 0) {
            $status = 'NEUTRAL';
            $message = "Aún no has registrado ingresos este mes. Registra tus entradas para que pueda asesorarte.";
            $color = 'grey';
        } 
        elseif ($balance < 0) {
            // Gasta más de lo que gana
            $status = 'CRÍTICO';
            $color = 'red';
            $recommendedMonthly = 0;
            $message = "Tus gastos superan tus ingresos. No es momento de ahorrar, sino de recortar gastos.";
            $alert = "¡Estás gastando un " . number_format((($expenses / $incomes) - 1) * 100, 0) . "% más de tu capacidad!";
        } 
        elseif ($balance < ($incomes * 0.10)) {
            // Sobra menos del 10%
            $status = 'AJUSTADO';
            $color = 'orange';
            $recommendedMonthly = $balance; // Ahorra lo que sobre
            $message = "Estás llegando a fin de mes muy justo. Intenta ahorrar cualquier excedente pequeño.";
            $alert = "Estás usando el " . number_format(($expenses / $incomes) * 100, 0) . "% de tus ingresos.";
        } 
        else {
            // Saludable
            $status = 'SALUDABLE';
            $color = 'green';
            // Recomendamos el 20%, pero si sobra menos de eso, recomendamos lo que sobra para no endeudarlo
            $recommendedMonthly = min($idealSavings, $balance); 
            $message = "¡Tus finanzas están sanas! Tienes buena capacidad para ahorrar.";
        }

        return response()->json([
            'financial_data' => [
                'total_income' => $incomes,
                'total_expenses' => $expenses,
                'current_balance' => $balance,
            ],
            'analysis' => [
                'status' => $status,
                'status_color' => $color,
                'message' => $message,
                'alert' => $alert, // Puede ser null
            ],
            'recommendation' => [
                'monthly_amount' => $recommendedMonthly,
                'weekly_amount' => $recommendedMonthly / 4,
            ]
        ], 200);
    }
}