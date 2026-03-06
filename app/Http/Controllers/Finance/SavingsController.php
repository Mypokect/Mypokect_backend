<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class SavingsController extends Controller
{
    /**
     * Analyze savings capacity.
     *
     * Calculates monthly/weekly savings capacity using the 50/30/20 rule, plus an AI-generated coaching insight.
     */
    public function analyze()
    {
        try {
            $user = Auth::user();

            // 1. OBTENER DATOS REALES (Mes Actual)
            $startOfMonth = Carbon::now()->startOfMonth();
            $endOfMonth = Carbon::now()->endOfMonth();

            $incomes = $user->movements()
                ->where('type', 'income')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            $expenses = $user->movements()
                ->where('type', 'expense')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            // 2. CÁLCULO MATEMÁTICO DE CAPACIDAD (La lógica dura)
            $balance = $incomes - $expenses;
            $idealSavings = $incomes * 0.20; // Meta ideal del 20%

            // Si lo que sobra es MENOR al 20%, la meta real es lo que sobra (para no endeudarlo).
            // Si lo que sobra es MAYOR al 20%, la meta se queda en el 20% (y el resto es libre inversión/gustos).
            $realSavingsCapacity = ($balance < $idealSavings) ? max(0, $balance) : $idealSavings;

            // 3. CONSULTAR A LA IA (El toque humano)
            $aiResponse = $this->consultarCoachIA($incomes, $expenses, $realSavingsCapacity);

            return response()->json([
                'math_data' => [
                    'ingresos' => $incomes,
                    'gastos' => $expenses,
                    'balance_real' => $balance,
                    'ahorro_mensual_sugerido' => $realSavingsCapacity,
                    'ahorro_semanal_sugerido' => $realSavingsCapacity / 4,
                ],
                'ai_insight' => $aiResponse,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['error' => 'Error', 'message' => $e->getMessage()], 500);
        }
    }

    private function consultarCoachIA($ingresos, $gastos, $capacidadAhorro)
    {
        $apiKey = config('services.groq.key');

        // Contexto financiero
        $porcentajeGasto = ($ingresos > 0) ? ($gastos / $ingresos) * 100 : 0;
        $estado = 'Indefinido';
        if ($gastos > $ingresos) {
            $estado = 'Déficit (Gastando de más)';
        } elseif ($porcentajeGasto > 90) {
            $estado = 'Ajustado (Poco margen)';
        } else {
            $estado = 'Saludable';
        }

        $prompt = <<<PROMPT
        ROL: Eres un coach financiero personal que da retroalimentación breve y directa.

        TAREA: Genera un diagnóstico financiero corto basado en los datos del usuario.

        INPUT:
        - ingresos: $ingresos
        - gastos: $gastos
        - estado: $estado
        - capacidad_ahorro: $capacidadAhorro

        LÓGICA DE DECISIÓN:
        - Si gastos > ingresos → tono de alerta, color "red".
        - Si gastos > 90% de ingresos → tono de precaución, color "orange".
        - Si gastos ≤ 90% de ingresos → tono motivacional, color "green".

        REGLAS:
        1. titulo: frase corta de impacto (máx 6 palabras).
        2. mensaje: exactamente 2 oraciones cortas. Si hay déficit, advierte. Si está bien, motiva.
        3. alerta: true si gastos > 90% de ingresos, false si no.
        4. color: "red", "orange" o "green" según la lógica de arriba.
        5. Todo el texto en español.

        OUTPUT — JSON válido, sin texto adicional:
        {"titulo": "", "mensaje": "", "alerta": false, "color": "green"}

        PROMPT;

        $modelos = ['llama-3.1-8b-instant', 'gemma2-9b-it'];

        foreach ($modelos as $modelo) {
            try {
                $response = Http::withOptions([])->withHeaders([
                    'Authorization' => "Bearer $apiKey", 'Content-Type' => 'application/json',
                ])->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $modelo,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'temperature' => 0.4,
                    'response_format' => ['type' => 'json_object'],
                ]);

                if ($response->successful()) {
                    return json_decode($response['choices'][0]['message']['content'], true);
                }
            } catch (\Exception $e) {
            }
        }

        // Fallback por si falla la IA
        return [
            'titulo' => 'Análisis Financiero',
            'mensaje' => 'Basado en tus números, esta es tu capacidad real de ahorro.',
            'alerta' => ($gastos > $ingresos),
            'color' => ($gastos > $ingresos) ? 'red' : 'blue',
        ];
    }
}
