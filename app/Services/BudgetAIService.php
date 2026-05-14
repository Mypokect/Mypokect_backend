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
        You are a financial planning AI specialized in realistic personal budgeting for Latin American users.

        TASK:
        Generate a realistic budget distribution for a "$planType" plan.

        INPUT:
        - title: "$title"
        - description: "$description"
        - total_amount: $amount

        RULES:
        - Return ONLY valid raw JSON.
        - The response MUST start with '{' and end with '}'.
        - No markdown.
        - No explanations.
        - No extra text.
        - JSON keys must always be in English.
        - Detect the user's language from the title/description and write all text values in that language.
        - Generate between 3 and 7 categories.
        - Category amounts MUST sum EXACTLY to $amount.
        - Use realistic spending proportions based on the actual type of event or plan.
        - NEVER split amounts evenly unless it is genuinely realistic.
        - Avoid generic categories like "Other" unless absolutely necessary.
        - Each category must have:
        - name
        - amount
        - reason
        {$tagsInstruction}

        GENERAL_ADVICE:
        - Write ONE short strategic recommendation specific to the plan.

        TIME DETECTION:
        Convert duration mentions into days using these rules:
        - "day" = 1
        - "week" = 7
        - "biweekly" / "fortnight" = 15
        - "month" = 30
        - If no duration exists, use 30.

        PERIOD RULES:
        - <= 7 days → "weekly"
        - 8-15 days → "biweekly"
        - 16-31 days → "monthly"
        - >31 days → "custom"

        OUTPUT FORMAT:
        {
        "categories": [
            {
            "name": "string",
            "amount": 0,
            "reason": "string"{$suggestedTagsField}
            }
        ],
        "general_advice": "string",
        "suggested_period": "weekly|biweekly|monthly|custom",
        "duration_days": 30
        }

        VALIDATION RULES:
        - Ensure the total sum of all category amounts equals EXACTLY $amount.
        - Ensure all numeric values are integers unless decimals are required.
        - Ensure valid JSON syntax.
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
