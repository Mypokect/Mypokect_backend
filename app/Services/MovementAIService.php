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
                Log::error('Empty response from Groq API');
                throw new \Exception('AI services unavailable');
            }

            Log::debug('Raw response received', ['response' => substr($response, 0, 150).'...']);

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
            Log::error('ERROR in suggestFromVoice', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
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
            SYSTEM:
            Return ONLY valid JSON. Any text outside JSON is an error.

            TASK:
            Extract structured transaction data from a voice transcription.

            CORE RULES:
            - Output MUST be valid JSON only
            - Do NOT explain, comment, or add extra text
            - Do NOT invent information
            - Use default values when data is missing
            - FIRST detect the language from the transcription
            - ALL output text MUST be in that same language
            - NEVER mix languages

            INPUT:
            Transcription: "$transcription"
            User tags: [$tagsList]
            User goals: [$goalsList]

            LANGUAGE LOGIC:
            - Detect language ONLY from the transcription
            - Spanish input → Spanish output
            - English input → English output
            - Apply language consistently to:
            - description
            - suggested_tag
            - goal label (if used)

            TRANSACTION LOGIC:
            - If money is spent or paid → expense
            - If money is received → income
            - If money is spent → IGNORE goals completely
            - Consider goals ONLY if user explicitly says they are saving money

            FIELDS DEFINITION:

            amount:
            - numeric only
            - convert k / mil / thousand / million
            - if unclear → 0

            description:
            - short summary of the action
            - max 4 words
            - no numbers, no amounts
            - same language as transcription

            type:
            - "income" if money received
            - otherwise "expense"

            payment_method:
            - "cash" ONLY if words like:
            - Spanish: efectivo, plata, billetes
            - English: cash
            - otherwise "digital"

            suggested_tag:
            - DEFAULT BEHAVIOR (most cases):
            - classify by SERVICE or ACTIVITY
            - ignore goals
            - If 90%+ semantic match with existing user tag → use it
            - Else return ONE generic category word
            - MUST be in user language
            - NEVER use object names (celular, phone, carro, laptop, hotel name, etc.)

            - GOAL BEHAVIOR (RARE, STRICT):
            - ONLY if ALL are true:
                1. Money is NOT spent
                2. Explicit saving verbs present (ahorrar, guardar, apartar, saving)
                3. Goal matches a user goal
            - ONLY then return:
                - Spanish: "Meta: <nombre>"
                - English: "Goal: <name>"

            has_invoice:
            - true ONLY if mentioned:
            - Spanish: factura, rut, electrónica
            - English: invoice
            - otherwise false

            OUTPUT (EXACT SCHEMA):
            {
            "amount": 0,
            "description": "",
            "type": "expense",
            "payment_method": "digital",
            "suggested_tag": "",
            "has_invoice": false
            }
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
            TASK: Categorize the transaction by the REAL TYPE OF EXPENSE.

        STRICT RULES:
        - Detect the language of the transaction description
        - The OUTPUT TAG MUST be in the SAME language as the description
        - If using an existing tag in a different language, TRANSLATE it to the description language
        - Determine what the money was actually spent on
        - IGNORE goals, savings, objects, or account names
        - Classify by service or activity
        - Return EXACTLY ONE tag
        - Use an existing tag if semantically appropriate
        - Otherwise suggest a NEW generic category
        - Avoid specific objects or product names
        - ONE word only
        - No explanations
        - No punctuation
        - No extra text

        Transaction:
        Description: "$description"
        Amount: $amount
        Existing tags: [$tagsList]

        Return ONLY the tag.
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
                if (str_contains($prompt, 'JSON only')) {
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
                Log::error('Invalid AI JSON response after all attempts');
                throw new \Exception('Invalid AI JSON response');
            }

            Log::debug('JSON decoded successfully', ['keys' => array_keys($data)]);

            // Normalize fields with defaults
            $result = [
                'description' => $data['description'] ?? 'Movimiento',
                'amount' => (float) ($data['amount'] ?? 0),
                'suggested_tag' => $this->normalizeTag($data),
                'type' => in_array($data['type'] ?? '', ['income', 'expense']) ? $data['type'] : 'expense',
                'payment_method' => in_array($data['payment_method'] ?? '', ['cash', 'digital']) ? $data['payment_method'] : 'digital',
                'has_invoice' => (bool) ($data['has_invoice'] ?? false),
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
            Log::error('ERROR in normalizeMovementSuggestion', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
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
}
