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
        if (str_contains($text, 'viaj') || str_contains($text, 'travel') || str_contains($text, 'vacaci')) return 'travel';
        if (str_contains($text, 'fiesta') || str_contains($text, 'boda') || str_contains($text, 'party') || str_contains($text, 'event')) return 'event';
        if (str_contains($text, 'compr') || str_contains($text, 'buy') || str_contains($text, 'lapt') || str_contains($text, 'carr')) return 'purchase';
        if (str_contains($text, 'proyect') || str_contains($text, 'remodel') || str_contains($text, 'constru')) return 'project';
        
        return 'other';
    }

    /**
     * Core AI Generation Logic
     */
    public function generateBudgetWithAI(string $title, float $amount, string $description): array
    {
        $language = $this->detectLanguage($title . ' ' . $description);
        $planType = $this->classifyPlanType($title . ' ' . $description);
        
        $langInstruction = $language === 'es' 
            ? "RESPOND ONLY IN SPANISH. Translate all category names and reasons to Spanish." 
            : "RESPOND ONLY IN ENGLISH.";

        $prompt = <<<PROMPT
        Role: Expert Financial Planner.
        Task: Create a detailed budget breakdown for a "$planType" plan.
        
        Context:
        - Title: "$title"
        - Description: "$description"
        - Total Budget: $amount
        
        CRITICAL Constraints:
        1. $langInstruction
        2. Create 3 to 7 logical expense categories relevant to "$planType".
        3. The sum of all category "amount" values MUST BE EXACTLY $amount. This is a strict mathematical requirement. Double-check your math before responding. Do not deviate.
        4. "reason" must be a short, specific justification for the expense (2-5 words).
        5. "general_advice" must be a concise, helpful, and strategic piece of advice related to the budget.
        
        Output format (JSON ONLY, no extra text or explanations):
        {
            "categories": [
                {"name": "string", "amount": number, "reason": "string"}
            ],
            "general_advice": "string"
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
                    'response_format' => ['type' => 'json_object']
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    if (!isset($data['choices'][0]['message']['content'])) {
                        Log::warning("AI response format unexpected ($model): " . json_encode($data));
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
                            'plan_type' => $planType
                        ];
                    }
                } else {
                    Log::error("AI API error ($model): " . $response->body());
                }

            } catch (\Exception $e) {
                Log::error("AI Service Exception ($model): " . $e->getMessage());
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
        Extract a financial expense from the text.

        Text: "$text"

        Rules:
        - Return expense name and exact numeric amount.
        - Convert k/mil/millón/millones to numbers (200k → 200000, un millón → 1000000).
        - Fix spelling of the name.
        - Output ONLY valid JSON.

        Format:
        {
        "name": "",
        "amount": 0
        }

        PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl, [
                'model' => 'llama-3.1-8b-instant', // Modelo rápido ideal para esto
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.1, // Muy preciso, poca creatividad
                'response_format' => ['type' => 'json_object']
            ]);

            if ($response->successful()) {
                $content = $response['choices'][0]['message']['content'];
                return json_decode($content, true);
            }
        } catch (\Exception $e) {
            Log::error("Groq Voice Error: " . $e->getMessage());
        }

        // Fallback si falla la IA (intentar sacar números a la fuerza)
        preg_match_all('/\d+/', $text, $matches);
        $amount = isset($matches[0][0]) ? (float)$matches[0][0] : 0;
        
        return [
            "name" => trim(str_replace($amount, '', $text)),
            "amount" => $amount
        ];
    }
}