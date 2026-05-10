<?php

namespace App\Services;

use App\Models\SavingGoal;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MovementAIService
{
    protected string $apiKey;

    protected string $baseUrl = 'https://api.groq.com/openai/v1/chat/completions';

    protected array $models = ['llama-3.1-8b-instant', 'gemma2-9b-it'];

    public function __construct()
    {
        $this->apiKey = config('services.groq.key');
    }

    /**
     * Suggest movement data from voice transcription using AI.
     * Extracts COMPLETE movement context from voice: amount, description, type, payment_method, tag, invoice.
     *
     * @return array{description: string, amount: float, suggested_tag: string, type: string, payment_method: string, has_invoice: bool}
     */
    public function suggestFromVoice(string $transcription, User $user): array
    {
        Log::info('=== SUGGEST FROM VOICE STARTED ===');
        Log::info('User ID', ['user_id' => $user->id]);
        Log::info('Transcription', ['text' => substr($transcription, 0, 100).'...']);

        try {
            // Get user's existing tags for context
            Log::debug('Fetching user tags');
            $existingTags = Tag::where('user_id', $user->id)->pluck('name')->toArray();
            Log::info('Tags found', ['count' => count($existingTags), 'tags' => $existingTags]);
            $tagsList = empty($existingTags) ? 'None' : implode(', ', $existingTags);

            // Get user's saving goals for context
            Log::debug('Fetching user goals');
            $savingGoals = SavingGoal::where('user_id', $user->id)->pluck('name')->toArray();
            Log::info('Goals found', ['count' => count($savingGoals), 'goals' => $savingGoals]);
            $goalsList = empty($savingGoals) ? 'None' : implode(', ', $savingGoals);

            // Build prompt
            Log::debug('Building voice movement prompt');
            $prompt = $this->buildVoiceMovementPrompt($transcription, $tagsList, $goalsList);
            Log::debug('Prompt built', ['prompt_length' => strlen($prompt)]);

            // Call Groq API
            Log::debug('Calling Groq API for voice suggestion');
            $response = $this->callGroqAPI($prompt, 0.1);

            if (! $response) {
                Log::error('Empty response from Groq API — all models failed');
                Log::info('VOICE AUDIT', ['user_said' => $transcription, 'groq_responded' => null, 'outcome' => 'all_models_failed']);
                return $this->insufficientDataResponse();
            }

            Log::info('VOICE AUDIT', ['user_said' => $transcription, 'groq_responded' => substr($response, 0, 500)]);

            // Normalize response
            Log::debug('Normalizing movement suggestion');
            $result = $this->normalizeMovementSuggestion($response);

            Log::info('=== SUGGEST FROM VOICE COMPLETED ===');
            Log::info('Result', [
                'amount' => $result['amount'],
                'type' => $result['type'],
                'tag' => $result['suggested_tag'],
                'payment_method' => $result['payment_method'],
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('ERROR in suggestFromVoice — returning insufficient_data', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->insufficientDataResponse();
        }
    }

    /**
     * Suggest tag based on description and amount using AI.
     */
    public function suggestTag(string $description, float $amount, User $user): string
    {
        Log::info('=== SUGGEST TAG STARTED ===');
        Log::info('Input', ['description' => $description, 'amount' => $amount, 'user_id' => $user->id]);

        try {
            // Get existing tags ordered by popularity (most used first)
            Log::debug('Fetching user tags ordered by popularity');
            $existingTags = Tag::where('user_id', $user->id)
                ->withCount('movements')
                ->orderBy('movements_count', 'desc')
                ->pluck('name')
                ->toArray();
            Log::info('Tags fetched and ordered', ['count' => count($existingTags), 'tags' => $existingTags]);

            // Detect user's language
            Log::debug('Detecting user language');
            $language = $this->detectLanguage($description, $existingTags);
            Log::info('Language detected', ['language' => $language]);

            // Build optimized prompt
            Log::debug('Building tag prompt');
            $prompt = $this->buildTagPrompt($description, $amount, $existingTags, $language);

            // Call Groq API
            Log::debug('Calling Groq API for tag suggestion');
            $response = $this->callGroqAPI($prompt, 0.1, 15);

            if (! $response) {
                Log::warning('Empty response from Groq API, using fallback');
                $fallback = $language === 'es' ? 'Otros' : 'Other';
                Log::info('Using fallback tag', ['tag' => $fallback]);

                return $fallback;
            }

            Log::debug('Raw response received', ['response' => $response]);

            // Clean the response to extract ONLY the tag name
            $clean = trim($response);
            Log::debug('After trim', ['clean' => $clean]);

            // Strategy 1: Try to find the tag in existing tags (most reliable)
            Log::debug('=== STRATEGY 1: Search in existing tags ===');
            foreach ($existingTags as $tag) {
                if (stripos($clean, $tag) !== false) {
                    Log::info('STRATEGY 1 SUCCESS: Found in existing tags', ['tag' => $tag]);
                    Log::info('=== SUGGEST TAG COMPLETED ===', ['result' => $tag, 'strategy' => 1]);

                    return $tag;
                }
            }
            Log::debug('STRATEGY 1 FAILED: Tag not found in existing tags');

            // Strategy 2: Remove common prefixes/suffixes that Groq adds
            Log::debug('=== STRATEGY 2: Remove prefixes ===');
            $prefixes = [
                'Based on the rules',
                'Based on the transaction',
                'I will categorize',
                'categorize as',
                'i will categorize',
                'As per the rules',
                'Following the rules',
                'The tag is',
                'The category is',
                'The answer is',
                'Answer:',
                'Output:',
                'Response:',
                'Tag:',
                'Category:',
                'Categoria:',
                'Etiqueta:',
                'As per',
                'the transaction',
                'following the rules',
            ];

            $prefixFound = null;
            foreach ($prefixes as $prefix) {
                if (stripos($clean, $prefix) === 0) {
                    Log::debug('Found prefix', ['prefix' => $prefix]);
                    $prefixFound = $prefix;
                    $clean = substr($clean, strlen($prefix));
                    break;
                }
            }
            if ($prefixFound) {
                Log::debug('After removing prefix', ['clean' => $clean]);
            }

            // Remove colons and extra content after colon
            if (strpos($clean, ':') !== false) {
                Log::debug('Found colon, extracting after colon');
                $parts = explode(':', $clean);
                $clean = end($parts);
                Log::debug('After colon extraction', ['clean' => $clean]);
            }

            // Remove special characters at start/end but keep internal characters
            $clean = trim($clean, ' "\'.,:-_*()[]{}');
            Log::debug('After trim special chars', ['clean' => $clean]);

            // Replace multiple spaces with single space
            $clean = trim(preg_replace('/\s+/', ' ', $clean));
            Log::debug('After space normalization', ['clean' => $clean]);

            // Get the last word (usually the tag after removing explanation)
            Log::debug('=== STRATEGY 3: Extract meaningful word ===');
            $words = explode(' ', $clean);
            Log::debug('Words split', ['words' => $words, 'count' => count($words)]);
            $lastWord = trim(end($words) ?? '');
            Log::debug('Last word extracted', ['word' => $lastWord]);

            // Remove any remaining special characters
            $lastWord = preg_replace('/[^a-zñáéíóúA-ZÑÁÉÍÓÚ0-9]/i', '', $lastWord);
            Log::debug('After char filtering', ['word' => $lastWord]);

            // Remove common filler words (en/es)
            $fillerWords = ['the', 'a', 'an', 'is', 'as', 'for', 'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'es', 'y', 'o', 'or', 'and'];
            if (in_array(strtolower($lastWord), $fillerWords)) {
                Log::debug('Last word is filler, searching for meaningful word');
                // Try to find a meaningful word
                for ($i = count($words) - 2; $i >= 0; $i--) {
                    $word = trim(preg_replace('/[^a-zñáéíóúA-ZÑÁÉÍÓÚ0-9]/i', '', $words[$i]));
                    if (! empty($word) && ! in_array(strtolower($word), $fillerWords) && strlen($word) > 2) {
                        Log::debug('Found meaningful word', ['word' => $word, 'index' => $i]);
                        $lastWord = $word;
                        break;
                    }
                }
            }

            $finalTag = ucfirst(strtolower(trim($lastWord))) ?: ($language === 'es' ? 'Otros' : 'Other');
            Log::info('STRATEGY 2/3 SUCCESS: Tag extracted', ['tag' => $finalTag]);
            Log::info('=== SUGGEST TAG COMPLETED ===', ['result' => $finalTag, 'strategy' => '2-3']);

            return $finalTag;

        } catch (\Exception $e) {
            Log::error('ERROR in suggestTag', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    /**
     * Detect user's preferred language from their existing tags.
     * Fallback: detect from description text.
     */
    protected function detectLanguage(string $description, array $existingTags): string
    {
        Log::debug('=== DETECT LANGUAGE STARTED ===');
        Log::debug('Input', ['description' => substr($description, 0, 50), 'tags_count' => count($existingTags)]);

        // Method 1: Check user's existing tags language
        Log::debug('METHOD 1: Checking existing tags');
        if (! empty($existingTags)) {
            $spanishWords = ['comida', 'transporte', 'hogar', 'salud', 'educación', 'ropa', 'servicios'];
            $tagText = strtolower(implode(' ', $existingTags));

            foreach ($spanishWords as $word) {
                if (str_contains($tagText, $word)) {
                    Log::info('METHOD 1 SUCCESS: Found Spanish word in tags', ['word' => $word]);
                    Log::debug('=== DETECT LANGUAGE COMPLETED ===', ['language' => 'es', 'method' => 1]);

                    return 'es';
                }
            }
            Log::debug('METHOD 1 FAILED: No Spanish words found in tags');
        }

        // Method 2: Check description language
        Log::debug('METHOD 2: Checking description language');
        if (preg_match('/\b(el|la|los|las|en|de|que|y|con|para|por)\b/i', $description)) {
            Log::info('METHOD 2 SUCCESS: Found Spanish pattern in description');
            Log::debug('=== DETECT LANGUAGE COMPLETED ===', ['language' => 'es', 'method' => 2]);

            return 'es';
        }
        Log::debug('METHOD 2 FAILED: No Spanish pattern found');

        // Default to English
        Log::info('Fallback to English');
        Log::debug('=== DETECT LANGUAGE COMPLETED ===', ['language' => 'en', 'method' => 'default']);

        return 'en';
    }

    /**
     * Build optimized prompt for VOICE MOVEMENT EXTRACTION ONLY.
     * Extracts COMPLETE movement context from voice transcription.
     * OPTIMIZED: 32% less tokens (-190 tokens vs 280).
     */
    protected function buildVoiceMovementPrompt(string $transcription, string $tagsList, string $goalsList): string
    {
        Log::debug('=== BUILD VOICE MOVEMENT PROMPT ===');
        Log::debug('Input', [
            'transcription_length' => strlen($transcription),
            'tags_list_length' => strlen($tagsList),
            'goals_list_length' => strlen($goalsList),
        ]);

        $prompt = <<<PROMPT
ROLE: You are a financial transaction extractor for a Colombian personal finance app. Convert voice/text input into structured JSON.

TASK: Extract exactly one transaction from the input below.

CRITICAL RULES:
- Return JSON ONLY. No explanations, no markdown, no extra text.
- NEVER return null. If you cannot confidently extract amount or action, return the template with empty/zero fields and set error_type to "insufficient_data".
- Detect the language. ALL text fields (description, suggested_tag) must match that language.

COLOMBIAN SLANG DICTIONARY (apply before parsing):
- "luca" / "lucas" = 1,000 pesos (e.g., "50 lucas" = 50000)
- "palo" / "palos" = 1,000,000 pesos (e.g., "2 palos" = 2000000)
- "me cayó" / "me entró" / "me llegó" / "me pagaron" / "me depositaron" = received money → type: income
- "gasté" / "pagué" / "compré" / "me cobró" / "me salió" = spent money → type: expense
- "guardé" / "aparté" / "ahorré" / "le metí a" = saving → check goals
- "plin" / "nequi" / "daviplata" / "transfiya" = digital payment_method
- "en efectivo" / "en billete" / "en plata física" / "en cash" = payment_method: cash
- "factura" / "rut" / "factura electrónica" / "fe" = has_invoice: true

RENT_TYPE CLASSIFICATION (income movements only — apply in this priority order):
1. laboral  → "sueldo" | "salario" | "nómina" | "nomina" | "pago de nómina" | "quincena" | "quincena de" | "me pagaron el sueldo" | "pago laboral" | "contrato"
2. honorarios → "honorarios" | "consultoría" | "consultoria" | "freelance" | "servicios profesionales" | "factura de servicios" | "pago de servicios"
3. capital   → "arriendo" | "arrendamiento" | "alquiler" | "intereses" | "rendimientos" | "dividendos" | "rentó" | "renta pasiva"
4. comercial → "venta" | "vendí" | "negocio" | "mercancía" | "mercancia" | "local" | "facturé" | "factura de venta" | "caja" | "caja del día"
5. otros     → any income not matching the above

INPUT:
Transcription: "$transcription"
User tags: [$tagsList]
User goals: [$goalsList]

DECISION LOGIC:
- Money spent/paid/bought → type: "expense"
- Money received/earned/salary → type: "income"
- Goals: ONLY set suggested_tag to "Meta: <name>" when ALL 3 are true: (1) not an expense, (2) explicit saving verb present, (3) a matching goal exists in the list above.

FIELD RULES:
amount: numeric only. Apply slang dictionary above. "k"=1000, "mil"=1000, "millón"=1000000. Default: 0.
description: 2–4 word summary. No numbers. Same language as input.
type: "expense" | "income".
payment_method: "cash" only for explicit cash words. Otherwise "digital".
suggested_tag: Classify by SERVICE or ACTIVITY (not object names). Use existing tag if 90%+ match. Otherwise one generic category word.
has_invoice: true only if invoice words present. Otherwise false.
rent_type: Income only → "laboral" | "honorarios" | "capital" | "comercial" | "otros". Null for expenses.
error_type: null if extraction succeeded. "insufficient_data" if amount is 0 AND description is empty (could not understand the command).

OUTPUT (return this exact structure, fill the fields):
{"amount": 0, "description": "", "type": "expense", "payment_method": "digital", "suggested_tag": "", "has_invoice": false, "rent_type": null, "error_type": null}
PROMPT;

        Log::debug('Prompt built', ['prompt_length' => strlen($prompt)]);

        return $prompt;
    }

    /**
     * Build optimized prompt for TAG SUGGESTION ONLY.
     * Returns ONLY the tag name, not the full movement context.
     * OPTIMIZED: 54% less tokens vs previous version (160 tokens).
     */
    protected function buildTagPrompt(string $description, float $amount, array $existingTags, string $language): string
    {
        Log::debug('=== BUILD TAG PROMPT ===');
        Log::debug('Input', [
            'description' => $description,
            'amount' => $amount,
            'tags_count' => count($existingTags),
            'language' => $language,
        ]);

        $tagsList = empty($existingTags) ? 'None' : implode(', ', $existingTags);

        $prompt = <<<PROMPT
ROLE: Transaction categorizer. Assigns one spending category tag.

TASK: Return ONE tag that classifies this transaction by service or activity type.

RULES:
- Output the tag word ONLY. No JSON, no explanation, no punctuation.
- Tag language must match the description language.
- Classify by what the money was spent on (service/activity).
- If an existing tag fits semantically → use it exactly.
- If none fits → return one new generic category word.
- NEVER return product names, brand names, or object names.

INPUT:
Description: "$description"
Amount: $amount
Existing tags: [$tagsList]
PROMPT;

        Log::debug('Prompt built', ['prompt_length' => strlen($prompt)]);

        return $prompt;
    }

    /**
     * Call Groq API with fallback to multiple models.
     */
    protected function callGroqAPI(string $prompt, float $temperature = 0.1, ?int $maxTokens = null): ?string
    {
        Log::debug('=== CALL GROQ API ===');
        Log::debug('Parameters', [
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'prompt_length' => strlen($prompt),
            'models_count' => count($this->models),
        ]);

        foreach ($this->models as $index => $model) {
            Log::info("Attempting model $index", ['model' => $model]);

            try {
                $payload = [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'temperature' => $temperature,
                ];

                // Add response format for JSON requests (voice suggestions)
                if (str_contains(strtolower($prompt), 'json only')) {
                    $payload['response_format'] = ['type' => 'json_object'];
                    Log::debug('JSON response format enabled');
                }

                // Add max tokens if specified (for tag suggestions)
                if ($maxTokens) {
                    $payload['max_tokens'] = $maxTokens;
                    Log::debug('Max tokens set', ['max_tokens' => $maxTokens]);
                }

                Log::debug('Sending request to Groq API', ['model' => $model, 'payload_keys' => array_keys($payload)]);

                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])->timeout(10)->post($this->baseUrl, $payload);

                Log::debug('Response received', ['status' => $response->status(), 'model' => $model]);

                if ($response->successful()) {
                    $content = $response['choices'][0]['message']['content'] ?? null;

                    if ($content) {
                        Log::info("SUCCESS with model $model", [
                            'model' => $model,
                            'response_length' => strlen($content),
                            'response_preview' => substr($content, 0, 100),
                        ]);

                        return trim($content);
                    }

                    Log::warning("Model $model returned empty content");
                } else {
                    Log::warning("Model $model returned unsuccessful status", [
                        'model' => $model,
                        'status' => $response->status(),
                        'error' => $response->body(),
                    ]);
                }

            } catch (\Exception $e) {
                Log::warning("Model $model failed with exception", [
                    'model' => $model,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }

        Log::error('All AI models failed - no successful response from any model');

        return null;
    }

    /**
     * Normalize and validate movement suggestion from AI response.
     *
     * @return array{description: string, amount: float, suggested_tag: string, type: string, payment_method: string, has_invoice: bool}
     */
    protected function normalizeMovementSuggestion(string $rawResponse): array
    {
        Log::info('=== NORMALIZE MOVEMENT SUGGESTION STARTED ===');
        Log::debug('Raw response', ['response' => substr($rawResponse, 0, 150).'...', 'length' => strlen($rawResponse)]);

        try {
            $data = json_decode($rawResponse, true);
            Log::debug('JSON decode attempt', ['success' => $data !== null, 'error' => json_last_error_msg()]);

            // If JSON parsing failed, try to extract JSON from text
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('JSON parsing failed, attempting to extract JSON from text');
                $jsonString = $this->extractJson($rawResponse);

                if ($jsonString) {
                    Log::debug('Extracted JSON', ['extracted' => substr($jsonString, 0, 150)]);
                    $data = json_decode($jsonString, true);
                    Log::debug('Second JSON decode attempt', ['success' => $data !== null]);
                } else {
                    Log::error('Could not extract JSON from response');
                }
            }

            if (! $data || ! is_array($data)) {
                Log::error('Invalid AI JSON response after all attempts — returning insufficient_data');
                return $this->insufficientDataResponse();
            }

            Log::debug('JSON decoded successfully', ['keys' => array_keys($data)]);

            // Normalize fields with defaults
            $type = in_array($data['type'] ?? '', ['income', 'expense']) ? $data['type'] : 'expense';
            $validRentTypes = ['laboral', 'honorarios', 'capital', 'comercial', 'otros'];
            $rentType = ($type === 'income' && in_array($data['rent_type'] ?? '', $validRentTypes))
                ? $data['rent_type']
                : null;

            // If AI explicitly flagged insufficient data, return the error struct
            if (isset($data['error_type']) && $data['error_type'] === 'insufficient_data') {
                Log::warning('AI returned error_type=insufficient_data', ['raw' => substr($rawResponse, 0, 200)]);
                return $this->insufficientDataResponse();
            }

            // Also treat zero-amount + empty description as insufficient
            $parsedAmount = (float) ($data['amount'] ?? 0);
            $parsedDescription = trim($data['description'] ?? '');
            if ($parsedAmount === 0.0 && $parsedDescription === '') {
                Log::warning('AI returned zero amount and empty description — insufficient_data');
                return $this->insufficientDataResponse();
            }

            $suggestedTag = $this->normalizeTag($data);
            $result = [
                'description'    => $parsedDescription ?: 'Movimiento',
                'amount'         => $parsedAmount,
                'suggested_tag'  => $suggestedTag,
                'type'           => $type,
                'payment_method' => in_array($data['payment_method'] ?? '', ['cash', 'digital']) ? $data['payment_method'] : 'digital',
                'has_invoice'    => (bool) ($data['has_invoice'] ?? false),
                'rent_type'      => $rentType,
                'is_goal'        => str_starts_with($suggestedTag, 'Meta:'),
                'error_type'     => null,
            ];

            Log::debug('Normalization completed', [
                'description' => $result['description'],
                'amount' => $result['amount'],
                'type' => $result['type'],
                'payment_method' => $result['payment_method'],
                'tag' => $result['suggested_tag'],
                'has_invoice' => $result['has_invoice'],
            ]);

            Log::info('=== NORMALIZE MOVEMENT SUGGESTION COMPLETED ===');

            return $result;

        } catch (\Exception $e) {
            Log::error('ERROR in normalizeMovementSuggestion — returning insufficient_data', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->insufficientDataResponse();
        }
    }

    /**
     * Returns the canonical "insufficient data" response struct.
     * Used whenever AI output cannot be parsed or understood.
     */
    private function insufficientDataResponse(): array
    {
        return [
            'description'    => '',
            'amount'         => 0,
            'suggested_tag'  => '',
            'type'           => 'expense',
            'payment_method' => 'digital',
            'has_invoice'    => false,
            'rent_type'      => null,
            'is_goal'        => false,
            'error_type'     => 'insufficient_data',
        ];
    }

    /**
     * Normalize tag field from various possible AI response formats.
     */
    protected function normalizeTag(array $data): string
    {
        Log::debug('=== NORMALIZE TAG STARTED ===');
        Log::debug('Data keys', ['keys' => array_keys($data)]);

        // Try different possible field names
        $tag = $data['suggested_tag'] ?? $data['tag'] ?? $data['category'] ?? 'General';
        Log::debug('Tag extracted from data', ['tag' => $tag, 'source' => 'raw']);

        // Capitalize first letter
        $normalized = ucfirst(strtolower($tag));
        Log::debug('=== NORMALIZE TAG COMPLETED ===', ['tag' => $normalized]);

        return $normalized;
    }

    /**
     * Match individual movements to budget categories using AI.
     * NEW APPROACH: Analyzes each movement separately based on its description.
     *
     * @param  array  $categories  Category names, e.g. ["Hotel", "Transporte", "Comida"]
     * @param  array  $movements  Each item: ["tag" => "Servicio", "description" => "hotel marriott", "amount" => 800000]
     * @return array Map of category name => array of unique tag names
     */
    public function matchMovementsToCategories(array $categories, array $movements): array
    {
        if (empty($movements) || empty($categories)) {
            $result = [];
            foreach ($categories as $cat) {
                $result[$cat] = [];
            }
            return $result;
        }

        // Limit movements to avoid huge prompts (take top by amount)
        $limitedMovements = collect($movements)
            ->sortByDesc('amount')
            ->take(150)
            ->values()
            ->toArray();

        $categoriesList = implode(', ', $categories);

        // Build movements list with indices
        $movementsList = [];
        foreach ($limitedMovements as $idx => $m) {
            $tag = $m['tag'] ?? 'Sin etiqueta';
            $desc = !empty($m['description']) ? $m['description'] : 'sin descripción';
            $amount = number_format($m['amount'] ?? 0, 0, '', ',');
            $movementsList[] = "[$idx] Tag: \"$tag\" | Desc: \"$desc\" | \$$amount";
        }
        $movementsText = implode("\n", $movementsList);

        $prompt = <<<PROMPT
ROLE: You are a budget expense classifier. You assign individual movements to budget categories.

TASK: Classify each movement into exactly one budget category. Return JSON only.

INPUT:
Categories: [$categoriesList]
Movements:
$movementsText

DECISION LOGIC:
1. DESCRIPTION has higher priority than TAG name.
2. If description is empty ("sin descripción") → use the TAG name for semantic matching.
3. Same TAG can go to DIFFERENT categories if descriptions differ:
   - [0] Tag:"Servicio" Desc:"hotel marriott" → Hospedaje
   - [1] Tag:"Servicio" Desc:"taxi centro" → Transporte
4. If multiple categories could fit → choose the most specific one.
5. Each movement goes to exactly ONE category.

RULES:
- Use EXACT category names (case-sensitive).
- ALL categories must appear in output (use [] if no movements match).
- Return JSON only. No explanations.

OUTPUT:
{"CategoryName": [0, 5, 12], "OtherCategory": [1, 3], "EmptyCategory": []}

Values are arrays of movement indices [idx].
PROMPT;

        Log::info('Llamando a Groq para analizar movimientos individuales', [
            'movements_count' => count($limitedMovements),
            'categories_count' => count($categories),
        ]);

        $response = $this->callGroqAPI($prompt, 0.2, 1500);

        if (!$response) {
            Log::warning('No se recibió respuesta de Groq - usando fallback de matching simple');
            return $this->simpleFallbackMatching($categories, $limitedMovements);
        }

        $jsonString = $this->extractJson($response);
        if (!$jsonString) {
            Log::warning('No se pudo extraer JSON de la respuesta');
            $result = [];
            foreach ($categories as $cat) {
                $result[$cat] = [];
            }
            return $result;
        }

        $decoded = json_decode($jsonString, true);
        if (!is_array($decoded)) {
            Log::error('JSON decodificado no es un array - usando fallback');
            return $this->simpleFallbackMatching($categories, $limitedMovements);
        }

        // NUEVA ESTRATEGIA: En lugar de solo tags, extraer keywords de las descripciones
        // para permitir matching preciso de movimientos individuales

        $result = [];
        foreach ($categories as $cat) {
            $result[$cat] = [
                'tags' => [],
                'keywords' => []
            ];
        }

        $categoryTags = []; // Para rastrear tags únicos por categoría
        $categoryKeywords = []; // Para rastrear keywords por categoría

        foreach ($categories as $cat) {
            $indices = $decoded[$cat] ?? [];
            $tags = [];
            $keywords = [];

            foreach ($indices as $idx) {
                if (isset($limitedMovements[$idx])) {
                    $tagName = $limitedMovements[$idx]['tag'] ?? 'Sin etiqueta';
                    $description = trim($limitedMovements[$idx]['description'] ?? '');

                    // Añadir tag único
                    if (!in_array($tagName, $tags)) {
                        $tags[] = $tagName;
                    }

                    // Extraer keywords de la descripción (palabras significativas)
                    if (!empty($description) && $description !== 'sin descripción') {
                        // Convertir a minúsculas y dividir en palabras
                        $words = preg_split('/[\s\-_,\.]+/', mb_strtolower($description), -1, PREG_SPLIT_NO_EMPTY);

                        // Filtrar palabras muy cortas y stopwords comunes
                        $stopwords = ['de', 'del', 'la', 'el', 'en', 'con', 'por', 'para', 'y', 'a', 'un', 'una', 'los', 'las'];
                        foreach ($words as $word) {
                            if (strlen($word) >= 3 && !in_array($word, $stopwords) && !is_numeric($word)) {
                                if (!in_array($word, $keywords)) {
                                    $keywords[] = $word;
                                }
                            }
                        }
                    }
                }
            }

            $categoryTags[$cat] = $tags;
            $categoryKeywords[$cat] = $keywords;
        }

        // Construir resultado con tags y keywords
        foreach ($categories as $cat) {
            $result[$cat] = [
                'tags' => array_values($categoryTags[$cat] ?? []),
                'keywords' => array_values($categoryKeywords[$cat] ?? [])
            ];
        }

        Log::info('Resultado de clasificación de movimientos con keywords', [
            'result' => $result,
        ]);

        // VERIFICAR: Si Groq retornó TODO vacío, usar fallback
        $allEmpty = true;
        foreach ($result as $cat => $data) {
            if (!empty($data['tags']) || !empty($data['keywords'])) {
                $allEmpty = false;
                break;
            }
        }

        if ($allEmpty && count($limitedMovements) > 0) {
            Log::warning('Groq retornó arrays vacíos para TODAS las categorías - usando fallback de matching simple');
            return $this->simpleFallbackMatching($categories, $limitedMovements);
        }

        // POST-PROCESAMIENTO: Para categorías que quedaron vacías,
        // intentar matching por nombre de categoría vs tags de movimientos
        $this->fillEmptyCategoriesFromTagNames($result, $categories, $limitedMovements);

        return $result;
    }

    /**
     * DEPRECATED: Old approach that aggregates tags.
     * Match user's spending tags to budget categories using AI.
     * Uses tag names, amounts AND movement descriptions for accurate semantic matching.
     *
     * @param  array  $categories  Category names, e.g. ["Hospedaje", "Transporte", "Comida"]
     * @param  array  $tagsWithAmounts  Each item: ["name" => "Hotel", "total" => 150000]
     * @param  array  $tagDescriptions  Map of tag name => array of movement descriptions
     * @return array Map of category name => array of tag names
     */
    public function matchTagsToCategories(array $categories, array $tagsWithAmounts, array $tagDescriptions = []): array
    {
        if (empty($tagsWithAmounts)) {
            return [];
        }

        $categoriesList = implode(', ', $categories);

        // Build tag info with descriptions for richer context
        $tagsInfo = [];
        foreach ($tagsWithAmounts as $t) {
            $line = "{$t['name']} (\${$t['total']})";
            $descs = $tagDescriptions[$t['name']] ?? [];
            if (! empty($descs)) {
                $descSample = implode(', ', array_slice($descs, 0, 5));
                $line .= " — descripciones: [$descSample]";
            }
            $tagsInfo[] = $line;
        }
        $tagsList = implode("\n", $tagsInfo);

        $prompt = <<<PROMPT
ROLE: You are a budget tag classifier. You assign user spending tags to budget categories.

TASK: Assign each tag to the single best-matching budget category. Return JSON only.

INPUT:
Categories: [$categoriesList]
Tags with spending data:
$tagsList

DECISION LOGIC:
1. DESCRIPTIONS have higher priority than tag name:
   - Tag "Varios" with descriptions ["almuerzo", "cena"] → Comida (descriptions reveal true type)
   - Tag "Servicio" with descriptions ["hotel marriott"] → Hospedaje
2. If NO descriptions → use tag name with semantic matching.
3. If descriptions are MIXED types → assign to the predominant type.
4. Each tag goes to exactly ONE category (best match).
5. If a tag does not fit ANY category → do not include it.

RULES:
- Use EXACT category names (case-sensitive).
- ALL categories must appear in output (use [] if no tags match).
- Return JSON only. No explanations.

OUTPUT:
{"Hospedaje": ["Hotel", "Servicio"], "Transporte": ["Uber"], "Comida": ["Varios"], "EmptyCategory": []}
PROMPT;

        $response = $this->callGroqAPI($prompt, 0.2, 1000);

        if (! $response) {
            return [];
        }

        $jsonString = $this->extractJson($response);
        if (! $jsonString) {
            return [];
        }

        $decoded = json_decode($jsonString, true);
        if (! is_array($decoded)) {
            return [];
        }

        // Ensure all categories are present in the output
        foreach ($categories as $cat) {
            if (! isset($decoded[$cat])) {
                $decoded[$cat] = [];
            }
        }

        return $decoded;
    }

    /**
     * Post-processing: fill empty categories by matching tag names to category names.
     * If a category got no AI assignment but its name matches a tag name, assign it.
     */
    protected function fillEmptyCategoriesFromTagNames(array &$result, array $categories, array $movements): void
    {
        // Collect all tags already assigned to categories
        $assignedTags = [];
        foreach ($result as $data) {
            foreach ($data['tags'] ?? [] as $tag) {
                $assignedTags[] = $tag;
            }
        }

        // Group movements by tag
        $movementsByTag = [];
        foreach ($movements as $m) {
            $tag = $m['tag'] ?? 'Sin etiqueta';
            if (!isset($movementsByTag[$tag])) {
                $movementsByTag[$tag] = [];
            }
            $movementsByTag[$tag][] = $m;
        }

        foreach ($categories as $catName) {
            // Skip if category already has tags
            if (!empty($result[$catName]['tags'])) {
                continue;
            }

            $catLower = mb_strtolower($catName);
            $catWords = preg_split('/[\s\-_]+/', $catLower, -1, PREG_SPLIT_NO_EMPTY);

            // Check if any unassigned tag matches this category name
            foreach ($movementsByTag as $tagName => $tagMovements) {
                $tagLower = mb_strtolower($tagName);

                // Match: tag name appears in category name, or category word appears in tag name
                $matches = false;
                foreach ($catWords as $word) {
                    if (strlen($word) < 3) continue;
                    if (mb_strpos($tagLower, $word) !== false || mb_strpos($catLower, $tagLower) !== false) {
                        $matches = true;
                        break;
                    }
                }

                if ($matches && !in_array($tagName, $result[$catName]['tags'])) {
                    $result[$catName]['tags'][] = $tagName;

                    // Extract keywords from movement descriptions
                    $stopwords = ['de', 'del', 'la', 'el', 'en', 'con', 'por', 'para', 'y', 'a', 'un', 'una', 'los', 'las'];
                    foreach ($tagMovements as $mov) {
                        $desc = trim($mov['description'] ?? '');
                        if (!empty($desc) && $desc !== 'sin descripción') {
                            $words = preg_split('/[\s\-_,\.]+/', mb_strtolower($desc), -1, PREG_SPLIT_NO_EMPTY);
                            foreach ($words as $word) {
                                if (strlen($word) >= 3 && !in_array($word, $stopwords) && !is_numeric($word) && !in_array($word, $result[$catName]['keywords'])) {
                                    $result[$catName]['keywords'][] = $word;
                                }
                            }
                        }
                    }

                    Log::info("fillEmptyCategoriesFromTagNames: assigned tag '$tagName' to category '$catName'");
                }
            }
        }
    }

    /**
     * Extract the first valid JSON object from a string.
     */
    protected function extractJson(string $text): ?string
    {
        Log::debug('=== EXTRACT JSON STARTED ===');
        Log::debug('Input', ['text_length' => strlen($text), 'preview' => substr($text, 0, 100)]);

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        Log::debug('Position search', ['start' => $start, 'end' => $end]);

        if ($start === false || $end === false) {
            Log::warning('Could not find JSON delimiters');
            Log::debug('=== EXTRACT JSON FAILED ===');

            return null;
        }

        $extracted = substr($text, $start, ($end - $start) + 1);
        Log::debug('=== EXTRACT JSON SUCCESS ===', ['extracted_length' => strlen($extracted)]);

        return $extracted;
    }

    /**
     * Simple fallback matching when AI fails.
     * Matches tags to categories based on semantic similarity in names.
     */
    protected function simpleFallbackMatching(array $categories, array $movements): array
    {
        Log::info('=== SIMPLE FALLBACK MATCHING STARTED ===', [
            'categories_count' => count($categories),
            'movements_count' => count($movements),
        ]);

        $result = [];
        foreach ($categories as $cat) {
            $result[$cat] = [
                'tags' => [],
                'keywords' => []
            ];
        }

        // Agrupar movimientos por tag
        $movementsByTag = [];
        foreach ($movements as $m) {
            $tag = $m['tag'] ?? 'Sin etiqueta';
            if (!isset($movementsByTag[$tag])) {
                $movementsByTag[$tag] = [];
            }
            $movementsByTag[$tag][] = $m;
        }

        Log::info('Tags únicos encontrados', ['tags' => array_keys($movementsByTag)]);

        // Para cada categoría, buscar tags que coincidan semánticamente
        foreach ($categories as $categoryName) {
            $categoryLower = mb_strtolower($categoryName);
            $categoryWords = preg_split('/[\s\-_]+/', $categoryLower, -1, PREG_SPLIT_NO_EMPTY);

            $matchedTags = [];
            $keywords = [];

            foreach ($movementsByTag as $tagName => $tagMovements) {
                $tagLower = mb_strtolower($tagName);
                $shouldMatch = false;

                // Estrategia 1: Coincidencia exacta de palabras
                foreach ($categoryWords as $word) {
                    if (strlen($word) < 3) continue;
                    if (mb_strpos($tagLower, $word) !== false || mb_strpos($categoryLower, $tagLower) !== false) {
                        $shouldMatch = true;
                        break;
                    }
                }

                // Estrategia 2: Matching semántico manual
                if (!$shouldMatch) {
                    $semanticRules = [
                        'servicio' => ['servicio', 'hotel', 'hospedaje', 'alojamiento', 'arriendo'],
                        'comida' => ['comida', 'alimento', 'restaurante', 'bebida'],
                        'transporte' => ['transporte', 'movilidad', 'taxi', 'uber', 'viaje'],
                        'compras' => ['compras', 'compra', 'shopping'],
                    ];

                    foreach ($semanticRules as $keyword => $synonyms) {
                        if (mb_strpos($tagLower, $keyword) !== false) {
                            foreach ($synonyms as $syn) {
                                if (mb_strpos($categoryLower, $syn) !== false) {
                                    $shouldMatch = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }

                if ($shouldMatch && !in_array($tagName, $matchedTags)) {
                    $matchedTags[] = $tagName;

                    // Extraer keywords de descripciones
                    foreach ($tagMovements as $mov) {
                        $desc = trim($mov['description'] ?? '');
                        if (!empty($desc) && $desc !== 'sin descripción') {
                            $words = preg_split('/[\s\-_,\.]+/', mb_strtolower($desc), -1, PREG_SPLIT_NO_EMPTY);
                            $stopwords = ['de', 'del', 'la', 'el', 'en', 'con', 'por', 'para', 'y', 'a', 'un', 'una', 'los', 'las'];
                            foreach ($words as $word) {
                                if (strlen($word) >= 3 && !in_array($word, $stopwords) && !is_numeric($word) && !in_array($word, $keywords)) {
                                    $keywords[] = $word;
                                }
                            }
                        }
                    }
                }
            }

            $result[$categoryName] = [
                'tags' => array_values($matchedTags),
                'keywords' => array_values($keywords)
            ];

            Log::info("Fallback matching para '{$categoryName}'", [
                'matched_tags' => $matchedTags,
                'keywords_count' => count($keywords),
            ]);
        }

        Log::info('=== SIMPLE FALLBACK MATCHING COMPLETED ===');
        return $result;
    }
}
