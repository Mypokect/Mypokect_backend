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
    public function generateBudgetWithAI(string $title, float $amount, string $description): array
    {
        $language = $this->detectLanguage($title.' '.$description);
        $planType = $this->classifyPlanType($title.' '.$description);

        $langInstruction = $language === 'es'
            ? 'RESPOND ONLY IN SPANISH. Translate all category names and reasons to Spanish.'
            : 'RESPOND ONLY IN ENGLISH.';

        $prompt = <<<PROMPT
        Create a realistic and structured budget for a "$planType" plan.

        Input:
        - title: "$title"
        - description: "$description"
        - total: $amount

        Language:
        - Detect the input language.
        - ALL text values must be in that language.
        - JSON keys remain in English.

        Process rules:
        1. First infer the real plan intent (e.g. travel, event, study, purchase, project).
        2. Use common real-world spending patterns for that plan.
        3. Avoid generic categories unless strictly necessary.
        4. Categories must be practical, specific, and non-overlapping.

        Budget rules:
        - Create 3–7 relevant categories.
        - Each category must include:
        - name
        - amount
        - reason (2–5 words, concrete)
        - The sum of all amounts MUST equal total exactly.
        - Amount distribution must be logical (no random splits).

        Advice:
        - Add ONE short, strategic general_advice related to the plan.

        Output:
        - Return ONLY valid JSON.
        - No explanations, no extra text.

        Format:
        {
        "categories": [
            { "name": "", "amount": 0, "reason": "" }
        ],
        "general_advice": ""
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
                    'temperature' => 0.45,
                    'response_format' => ['type' => 'json_object'],
                ]);

                if ($response->successful()) {
                    $data = $response->json();

                    if (! isset($data['choices'][0]['message']['content'])) {
                        Log::warning("AI response format unexpected ($model): ".json_encode($data));

                        continue;
                    }

                    $contentString = $data['choices'][0]['message']['content'];
                    $content = json_decode($contentString, true);

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

                        // Recalculate percentages
                        foreach ($categories as &$cat) {
                            $cat['percentage'] = round(($cat['amount'] / $amount) * 100, 2);
                        }
                        unset($cat); // Unset reference

                        $content['categories'] = $categories;
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
     * Interpreta un comando de voz o texto corto para extraer nombre y monto.
     */
    public function interpretVoiceCommand(string $text): array
    {
        $prompt = <<<PROMPT
        Extract one expense (name, integer amount) from: "$text". Convert k/mil/millón/millones to numbers, clean the name, return ONLY valid JSON: {"name":"","amount":0}

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

                return json_decode($content, true);
            }
        } catch (\Exception $e) {
            Log::error('Groq Voice Error: '.$e->getMessage());
        }

        // Fallback si falla la IA (intentar sacar números a la fuerza)
        preg_match_all('/\d+/', $text, $matches);
        $amount = isset($matches[0][0]) ? (float) $matches[0][0] : 0;

        return [
            'name' => trim(str_replace($amount, '', $text)),
            'amount' => $amount,
        ];
    }
}
