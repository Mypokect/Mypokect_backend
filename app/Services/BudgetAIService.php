<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BudgetAIService
{
    protected string $apiKey;

    protected string $baseUrl = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = config('services.groq.key');
    }

    /**
     * Detect language from text.
     */
    public function detectLanguage(string $text): string
    {
        if (preg_match('/\b(el|la|los|las|en|de|que|y|a)\b/i', $text)) {
            return 'es';
        }

        return 'en';
    }

    /**
     * Classify plan type.
     */
    public function classifyPlanType(string $text): string
    {
        $text = strtolower($text);
        if (str_contains($text, 'viaj') || str_contains($text, 'travel') || str_contains($text, 'vacaci')) {
            return 'travel';
        }
        if (str_contains($text, 'fiesta') || str_contains($text, 'boda') || str_contains($text, 'cumple')) {
            return 'party';
        }
        if (str_contains($text, 'event') || str_contains($text, 'conferencia') || str_contains($text, 'meeting')) {
            return 'event';
        }
        if (str_contains($text, 'compr') || str_contains($text, 'buy') || str_contains($text, 'lapt') || str_contains($text, 'carr')) {
            return 'purchase';
        }
        if (str_contains($text, 'proyect') || str_contains($text, 'reforma') || str_contains($text, 'remodel') || str_contains($text, 'constru')) {
            return 'project';
        }

        return 'other';
    }

    /**
     * Core AI Generation Logic
     */
    public function generateBudgetWithAI(string $title, float $amount, string $description, array $userTags = []): array
    {
        $language = $this->detectLanguage($title.' '.$description);
        $planType = $this->classifyPlanType($title.' '.$description);

        $tagsList = empty($userTags) ? 'None' : implode(', ', $userTags);

        $tagsInstruction = empty($userTags)
            ? ''
            : <<<TAGS

        Tag matching:
        - The user has these existing tags: [$tagsList]
        - For each category, suggest which of these tags correspond to that spending category.
        - Only suggest tags that are semantically related to the category.
        - If no existing tag matches, return an empty array.
        - Do NOT invent new tags — only use tags from the list above.
        TAGS;

        $suggestedTagsField = empty($userTags)
            ? ''
            : ', "suggested_tags": ["tag1"]';

        $prompt = <<<PROMPT
ROL: Eres un planificador financiero experto especializado en finanzas personales latinoamericanas. Creas presupuestos realistas basados en patrones de gasto del mundo real para distintos tipos de planes.

CONTEXTO: El usuario está planificando un evento de tipo "$planType" y necesita distribuir un monto total en categorías prácticas y específicas. La distribución debe reflejar proporciones reales de gasto, no partes iguales.

OBJETIVO: Generar un presupuesto estructurado con entre 3 y 7 categorías que sumen EXACTAMENTE $amount.

CONDICIONES:
- Responde ÚNICAMENTE con JSON válido y puro. El primer carácter DEBE ser '{' y el último '}'.
- Sin texto previo, sin explicaciones, sin Markdown, sin bloques de código.
- Idioma: detecta el idioma del título/descripción. Todos los valores de texto van en ese idioma. Las keys JSON siempre en inglés.
- La SUMA de todos los amounts DEBE ser exactamente $amount — distribución lógica, NUNCA partes iguales.
- Infiere la intención real del plan y usa patrones de gasto reales para ese tipo de evento.
- No uses la categoría "Otros" a menos que sea estrictamente necesario.
- general_advice: UNA frase corta y estratégica, específica para el tipo de plan.
{$tagsInstruction}

CONVERSIONES DE TIEMPO:
| Expresión    | Días |
|--------------|------|
| "5 días"     | 5    |
| "semana"     | 7    |
| "quincena"   | 15   |
| "mes"        | 30   |
| Sin mención  | 30   |

suggested_period: "weekly" (≤7d) | "biweekly" (8-15d) | "monthly" (16-31d) | "custom" (>31d)

ENTRADA:
- título: "$title"
- descripción: "$description"
- monto total: $amount

FORMATO DE SALIDA (JSON puro, primer carácter '{', sin texto adicional):
{
  "categories": [
    { "name": "", "amount": 0, "reason": ""{$suggestedTagsField} }
  ],
  "general_advice": "",
  "suggested_period": "monthly",
  "duration_days": 30
}
PROMPT;

        $models = ['llama-3.1-8b-instant', 'gemma2-9b-it', 'llama3-8b-8192'];

        foreach ($models as $model) {
            try {
                Log::info("Attempting AI budget generation with model: $model");
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])->post($this->baseUrl, [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'temperature' => 0.3,
                    'response_format' => ['type' => 'json_object'],
                ]);

                if ($response->successful()) {
                    $data = $response->json();

                    if (! isset($data['choices'][0]['message']['content'])) {
                        Log::warning("AI response format unexpected ($model): ".json_encode($data));

                        continue;
                    }

                    $contentString = $data['choices'][0]['message']['content'];
                    $content = $this->safeJsonDecode($contentString);

                    if (isset($content['categories']) && is_array($content['categories']) && count($content['categories']) > 0) {

                        // ===== SELF-HEALING & VALIDATION LOGIC =====
                        $categories = $content['categories'];
                        $currentSum = array_sum(array_column($categories, 'amount'));

                        if ($currentSum > 0 && abs($currentSum - $amount) > 0.01) {
                            Log::warning("AI returned inexact sum ($currentSum instead of $amount). Self-correcting.");
                            $scalingFactor = $amount / $currentSum;
                            $correctedSum = 0;

                            foreach ($categories as $key => &$cat) {
                                // Scale the amount and round to 2 decimal places
                                $cat['amount'] = round($cat['amount'] * $scalingFactor, 2);
                                $correctedSum += $cat['amount'];
                            }
                            unset($cat); // Unset reference

                            // Adjust the last element to compensate for rounding errors
                            $difference = $amount - $correctedSum;
                            if (abs($difference) > 0) {
                                $categories[count($categories) - 1]['amount'] += $difference;
                                Log::info("Applied rounding correction of: $difference");
                            }
                        }

                        // Recalculate percentages and normalize suggested_tags
                        foreach ($categories as &$cat) {
                            $cat['percentage'] = round(($cat['amount'] / $amount) * 100, 2);
                            $cat['suggested_tags'] = isset($cat['suggested_tags']) && is_array($cat['suggested_tags'])
                                ? array_values(array_intersect($cat['suggested_tags'], $userTags))
                                : [];
                        }
                        unset($cat); // Unset reference

                        $content['categories'] = $categories;

                        // Validate timing fields (defaults if AI omitted them)
                        $validPeriods = ['weekly', 'biweekly', 'monthly', 'custom'];
                        if (!isset($content['suggested_period']) || !in_array($content['suggested_period'], $validPeriods)) {
                            $content['suggested_period'] = 'monthly';
                        }
                        $content['duration_days'] = isset($content['duration_days']) && is_numeric($content['duration_days'])
                            ? max(1, (int) $content['duration_days'])
                            : 30;
                        // =============================================

                        return [
                            'success' => true,
                            'data' => $content,
                            'language' => $language,
                            'plan_type' => $planType,
                        ];
                    }
                } else {
                    Log::error("AI API error ($model): ".$response->body());
                }

            } catch (\Exception $e) {
                Log::error("AI Service Exception ($model): ".$e->getMessage());
            }
        }

        return ['success' => false, 'message' => 'AI generation failed after trying multiple models.'];
    }

    /**
     * Extrae JSON puro de una respuesta de IA que puede contener Markdown u otro texto.
     * Intenta primero un json_decode directo; si falla, extrae el bloque {...} más externo.
     */
    private function safeJsonDecode(string $content): ?array
    {
        // Happy path: respuesta ya es JSON válido
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Fallback: limpiar bloques de código Markdown y extraer {...}
        $cleaned = preg_replace('/```(?:json)?\s*/i', '', $content);
        $cleaned = preg_replace('/```\s*/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $start = strpos($cleaned, '{');
        $end   = strrpos($cleaned, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $jsonStr = substr($cleaned, $start, $end - $start + 1);
            $decoded = json_decode($jsonStr, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                Log::warning('BudgetAIService: JSON extracted via markdown-strip fallback');
                return $decoded;
            }
        }

        Log::error('BudgetAIService: safeJsonDecode failed', ['raw' => substr($content, 0, 200)]);
        return null;
    }

    /**
     * Interpreta un comando de voz o texto corto para extraer nombre y monto.
     */
    public function interpretVoiceCommand(string $text): array
    {
        $prompt = <<<PROMPT
ROL: Eres un parser de texto financiero en español latinoamericano. Tu única función es extraer el nombre y el monto de un gasto descrito en lenguaje natural.

CONTEXTO: El usuario dicta o escribe un gasto de forma casual, usando abreviaturas, jerga local, o expresiones coloquiales. El texto puede ser muy corto (2-3 palabras) o más elaborado.

OBJETIVO: Extraer exactamente UN gasto (nombre y monto) del texto del usuario.

CONDICIONES:
- Responde ÚNICAMENTE con JSON puro. El primer carácter DEBE ser '{'.
- Sin Markdown, sin bloques de código, sin texto adicional.
- Si hay múltiples gastos → extrae solo el primero mencionado.
- Si no se detecta monto → amount = 0.
- amount debe ser un número entero positivo (sin decimales ni comas).
- name debe ser limpio: sin montos, sin artículos innecesarios, primera letra mayúscula.

CONVERSIONES DE MONTOS:
| Expresión            | Factor      |
|----------------------|-------------|
| k                    | ×1.000      |
| mil                  | ×1.000      |
| luca / lucas         | ×1.000      |
| millón / millones    | ×1.000.000  |
| palo / palos         | ×1.000.000  |

EJEMPLOS:
| Entrada                      | name              | amount   |
|------------------------------|-------------------|----------|
| "Arriendo 900k"              | "Arriendo"        | 900000   |
| "Mercado 150 mil"            | "Mercado"         | 150000   |
| "Gasolina 80 lucas"          | "Gasolina"        | 80000    |
| "Pasajes aéreos 2 millones"  | "Pasajes aéreos"  | 2000000  |
| "Netflix"                    | "Netflix"         | 0        |

ENTRADA: "$text"

FORMATO DE SALIDA (JSON puro, sin texto adicional):
{"name": "", "amount": 0}
PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl, [
                'model' => 'llama-3.1-8b-instant', // Modelo rápido ideal para esto
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.1, // Muy preciso, poca creatividad
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                $content = $response['choices'][0]['message']['content'];
                $decoded = $this->safeJsonDecode($content);
                if ($decoded !== null) {
                    return $decoded;
                }
            }
        } catch (\Exception $e) {
            Log::error('Groq Voice Error: '.$e->getMessage());
        }

        // Fallback numérico si la IA falla
        preg_match_all('/\d+/', $text, $matches);
        $amount = isset($matches[0][0]) ? (float) $matches[0][0] : 0;

        return [
            'name'   => trim(str_replace((string) $amount, '', $text)),
            'amount' => $amount,
        ];
    }
}
