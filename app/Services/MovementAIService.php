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
            $existingTags = Tag::where('user_id', $user->id)->pluck('name')->toArray();
            $savingGoals  = SavingGoal::where('user_id', $user->id)->pluck('name')->toArray();

            Log::info('Context loaded', ['tags' => count($existingTags), 'goals' => count($savingGoals)]);

            // ── Layers 1–3: Cache → Rule engine → Short LLM (<80 tokens) ─────
            /** @var HybridTransactionParser $parser */
            $parser = app(HybridTransactionParser::class);
            $hybrid = $parser->parse($transcription, $existingTags, $savingGoals);

            Log::info('HybridParser result', [
                '_source'    => $hybrid['_source'],
                'amount'     => $hybrid['amount'],
                'type'       => $hybrid['type'],
                'error_type' => $hybrid['error_type'],
            ]);

            if ($hybrid['_source'] !== 'rules_partial') {
                $result = $this->hybridResultToResponse($hybrid);
                Log::info('=== SUGGEST FROM VOICE COMPLETED (hybrid) ===', [
                    'source' => $hybrid['_source'],
                    'amount' => $result['amount'],
                    'type'   => $result['type'],
                ]);
                return $result;
            }

            // ── Layer 4: Full LLM (only when hybrid completely failed) ────────
            Log::info('Hybrid rules_partial — falling back to full LLM prompt');

            $tagsList  = empty($existingTags) ? 'None' : implode(', ', $existingTags);
            $goalsList = empty($savingGoals)  ? 'None' : implode(', ', $savingGoals);
            $prompt    = $this->buildVoiceMovementPrompt($transcription, $tagsList, $goalsList);

            $response = $this->callGroqAPI($prompt, 0.1, null, true);

            if (! $response) {
                Log::error('Full LLM also failed — returning insufficient_data');
                Log::info('VOICE AUDIT', ['user_said' => $transcription, 'groq_responded' => null, 'outcome' => 'all_failed']);
                return $this->insufficientDataResponse();
            }

            Log::info('VOICE AUDIT', ['user_said' => $transcription, 'groq_responded' => substr($response, 0, 500)]);

            $result = $this->normalizeMovementSuggestion($response);
            Log::info('=== SUGGEST FROM VOICE COMPLETED (full LLM) ===', [
                'amount' => $result['amount'],
                'type'   => $result['type'],
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('ERROR in suggestFromVoice — returning insufficient_data', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
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
     * Build the voice movement extraction prompt.
     * Extracts: amount, type, description, payment_method, has_invoice,
     *           is_business_expense, rent_type, suggested_tag, error_type.
     */
    protected function buildVoiceMovementPrompt(string $transcription, string $tagsList, string $goalsList): string
    {
        $prompt = <<<PROMPT
You are a financial transaction extractor for informal Colombian Spanish.
Respond ONLY with a single valid JSON object. No markdown, no explanation, no extra text.

INPUT: "$transcription"

USER TAGS (reuse one if it fits): $tagsList
USER GOALS (if money goes toward a goal, prefix tag with "Meta:"): $goalsList

--- EXTRACTION RULES ---

1. TYPE
   "income"  = money received (llegó, pagaron, cobré, quincena, sueldo, abono, ingresó)
   "expense" = money spent (gasté, pagué, compré, transferí, mandé, salió)
   Default: "expense"

2. AMOUNT — convert slang to number:
   luca / lucas = 1000
   k / K        = 1000   (e.g. "30k" = 30000)
   palo / palos = 1000000
   millón / millones = 1000000
   medio palo   = 500000
   If no amount found: set amount=0 and error_type="insufficient_data"

3. DESCRIPTION
   Short phrase (max 5 words) describing what happened. Same language as input.
   Examples: "Almuerzo en restaurante", "Gasolina carro", "Quincena trabajo"

4. SUGGESTED_TAG
   Pick the single most relevant tag from USER TAGS above, or create a 1–2 word category.
   If it's a goal contribution, use "Meta: <goal name>".
   Merchant→category rules (NEVER use "Otros"/"Other"):
   Uber/Didi/taxi/TransMilenio/bus/gasolina → Transporte
   D1/Éxito/Jumbo/Carulla/Ara/supermercado → Mercado
   Rappi/restaurante/almuerzo/café/McDonald's → Comida
   Netflix/Spotify/Disney/cine/videojuego → Entretenimiento
   arriendo/administración/predial → Vivienda
   luz/agua/internet/Claro/Movistar/EPM/gas → Servicios
   farmacia/médico/EPS/hospital/droguería → Salud
   universidad/colegio/curso/Platzi → Educación
   ropa/zapatos/Zara/Nike/Adidas → Ropa
   gimnasio/gym/deporte/SmartFit → Deporte
   banco/crédito/préstamo/Nequi/ahorro → Finanzas

5. PAYMENT_METHOD
   "digital" = nequi, daviplata, transfiya, transferencia, tarjeta, app, bancolombia, bbva
   "cash"    = efectivo, billetes, plata física, en mano
   Default: "digital"

6. HAS_INVOICE
   true  = factura, fe, fce, recibo, boleta, IVA, electrónica
   false = everything else
   Default: false

7. IS_BUSINESS_EXPENSE
   true  = gasto de trabajo, empresa, negocio, deducible, cliente
   false = everything else
   Default: false

8. RENT_TYPE (only for income, else null)
   "laboral"    = sueldo, salario, quincena, nómina, contrato laboral
   "honorarios" = honorarios, freelance, consultoría, servicios profesionales
   "capital"    = arriendo, intereses, dividendos, inversión, rendimientos
   "comercial"  = venta, negocio, tienda, mercancía, comercio
   "otros"      = any other income that doesn't fit above
   If type="expense": always null

9. ERROR_TYPE
   Set "insufficient_data" if: amount=0 OR the input is unclear/incomplete.
   Otherwise: null

--- OUTPUT FORMAT (fill every field) ---
{"amount":0,"description":"","type":"expense","payment_method":"digital","has_invoice":false,"is_business_expense":false,"rent_type":null,"suggested_tag":"","error_type":null}
PROMPT;

        Log::debug('Voice prompt built', ['prompt_length' => strlen($prompt)]);

        return $prompt;
    }

    /**
     * Build the tag suggestion prompt.
     * Returns ONLY one tag name — no JSON, no explanation.
     */
    protected function buildTagPrompt(string $description, float $amount, array $existingTags, string $language): string
    {
        $tagsLine = empty($existingTags) ? 'none' : implode(', ', $existingTags);
        $lang     = $language === 'es' ? 'Spanish' : 'English';

        $prompt = <<<PROMPT
You are a financial transaction categorizer.
Respond with ONE tag name only. No punctuation. No explanation. No JSON.

Transaction: "$description" (\$$amount)
Existing tags: $tagsLine
Output language: $lang

Rules:
- Reuse an existing tag if it clearly matches the purpose.
- If no existing tag fits, create a 1–2 word category in $lang.
- Match by PURPOSE, not brand or object.
  "almuerzo ejecutivo" → Comida
  "taxi aeropuerto"    → Transporte
  "camiseta nike"      → Ropa
  "consulta médica"    → Salud
  "recarga celular"    → Telecomunicaciones

Tag:
PROMPT;

        return $prompt;
    }

    /**
     * Call Groq API with fallback to multiple models.
     */
    protected function callGroqAPI(string $prompt, float $temperature = 0.1, ?int $maxTokens = null, bool $useJsonMode = false): ?string
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

                // Add response format for JSON requests
                if ($useJsonMode) {
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
                'description'        => $parsedDescription ?: 'Movimiento',
                'amount'             => $parsedAmount,
                'suggested_tag'      => $suggestedTag,
                'type'               => $type,
                'payment_method'     => in_array($data['payment_method'] ?? '', ['cash', 'digital']) ? $data['payment_method'] : 'digital',
                'has_invoice'        => (bool) ($data['has_invoice'] ?? false),
                'is_business_expense'=> (bool) ($data['is_business_expense'] ?? false),
                'rent_type'          => $rentType,
                'is_goal'            => str_starts_with($suggestedTag, 'Meta:'),
                'error_type'         => null,
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
            'description'         => '',
            'amount'              => 0,
            'suggested_tag'       => '',
            'type'                => 'expense',
            'payment_method'      => 'digital',
            'has_invoice'         => false,
            'is_business_expense' => false,
            'rent_type'           => null,
            'is_goal'             => false,
            'error_type'          => 'insufficient_data',
        ];
    }

    /**
     * Convert HybridTransactionParser output to the suggestFromVoice response shape.
     * Adds `is_goal` (absent from the hybrid parser's output).
     */
    private function hybridResultToResponse(array $hybrid): array
    {
        $tag = $hybrid['suggested_tag'] ?? 'General';
        return [
            'description'         => $hybrid['description'],
            'amount'              => $hybrid['amount'],
            'suggested_tag'       => $tag,
            'type'                => $hybrid['type'],
            'payment_method'      => $hybrid['payment_method'],
            'has_invoice'         => $hybrid['has_invoice'],
            'is_business_expense' => $hybrid['is_business_expense'],
            'rent_type'           => $hybrid['rent_type'],
            'is_goal'             => str_starts_with($tag, 'Meta:'),
            'error_type'          => $hybrid['error_type'],
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
ROL: Eres un analista de presupuesto personal. Tu función es asignar cada movimiento individual a su categoría de presupuesto correcta.

CONTEXTO: El usuario tiene un presupuesto con categorías predefinidas. Cada movimiento tiene una etiqueta (tag) general y una descripción específica. La misma etiqueta puede corresponder a categorías distintas según la descripción del movimiento.

OBJETIVO: Asignar cada movimiento (identificado por su índice) a exactamente UNA categoría de presupuesto.

CONDICIONES:
- La DESCRIPCIÓN tiene mayor prioridad que el nombre del tag para la clasificación.
- Si la descripción dice "sin descripción" → usa el nombre del tag para matching semántico.
- Cada movimiento va a exactamente UNA categoría (la más específica que corresponda).
- TODAS las categorías deben aparecer en el JSON de salida (usa [] si ningún movimiento le corresponde).
- Usa los nombres EXACTOS de las categorías (sensible a mayúsculas/minúsculas).
- Responde SOLO con el JSON. Sin explicaciones, sin Markdown, sin texto adicional.
- NUNCA uses "Otros" ni "Other" — siempre asigna a la categoría más cercana disponible.

MERCHANT→CATEGORY RULES (úsalas para desambiguar):
Uber/Didi/taxi/TransMilenio/bus/gasolina → Transporte
D1/Éxito/Jumbo/Carulla/Ara/supermercado → Mercado
Rappi/restaurante/almuerzo/café/McDonald's → Comida
Netflix/Spotify/Disney/cine/videojuego → Entretenimiento
arriendo/administración/predial → Vivienda
luz/agua/internet/Claro/Movistar/EPM/gas → Servicios
farmacia/médico/EPS/hospital/droguería → Salud
universidad/colegio/curso → Educación
ropa/zapatos/Zara/Nike → Ropa
gimnasio/gym/deporte → Deporte
banco/crédito/préstamo/Nequi → Finanzas

EJEMPLOS AMBIGUOS (mismo tag, categorías distintas):
- [0] Tag:"Servicio" Desc:"hotel marriott"     → categoría "Hospedaje"
- [1] Tag:"Servicio" Desc:"taxi al centro"     → categoría "Transporte"
- [2] Tag:"Varios"   Desc:"almuerzo ejecutivo" → categoría "Comida"
- [3] Tag:"Varios"   Desc:"sin descripción"    → categoría más similar al nombre del tag

ENTRADA:
Categorías: [$categoriesList]
Movimientos (formato [índice] Tag: "..." | Desc: "..." | $monto):
$movementsText

FORMATO DE SALIDA (JSON puro, valores = arrays de índices de movimientos):
{"NombreCategoria": [0, 5, 12], "OtraCategoria": [1, 3], "CategoriaVacia": []}
PROMPT;

        Log::info('Llamando a Groq para analizar movimientos individuales', [
            'movements_count' => count($limitedMovements),
            'categories_count' => count($categories),
        ]);

        $response = $this->callGroqAPI($prompt, 0.2, 1500, true);

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
You are a budget categorization engine.
Respond ONLY with a valid JSON object. No markdown, no explanation.

TASK: Assign each spending tag to exactly ONE budget category.

Budget categories: [$categoriesList]

Spending tags with amounts and sample descriptions:
$tagsList

Rules:
- Each tag goes to the ONE most semantically similar category.
- All categories must appear in the output (use [] if none match).
- Use exact category names as keys (case-sensitive).
- Match by PURPOSE not by brand or product name.
- Output format: {"CategoryName": ["Tag1", "Tag2"], "OtherCategory": []}
PROMPT;

        $response = $this->callGroqAPI($prompt, 0.1, 1000, true);

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
            'movements_count'  => count($movements),
        ]);

        // Initialize result structure
        $result = [];
        foreach ($categories as $cat) {
            $result[$cat] = ['tags' => [], 'keywords' => []];
        }

        // Group movements by tag
        $movementsByTag = [];
        foreach ($movements as $m) {
            $tag = $m['tag'] ?? 'Sin etiqueta';
            $movementsByTag[$tag][] = $m;
        }

        Log::info('Tags únicos encontrados', ['tags' => array_keys($movementsByTag)]);

        $stopwords = ['de','del','la','el','en','con','por','para','y','a','un','una','los','las'];

        foreach ($categories as $categoryName) {
            $categoryLower = mb_strtolower($categoryName);
            $categoryWords = array_filter(
                preg_split('/[\s\-_]+/', $categoryLower, -1, PREG_SPLIT_NO_EMPTY),
                fn($w) => strlen($w) >= 3
            );

            $matchedTags = [];
            $keywords    = [];

            foreach ($movementsByTag as $tagName => $tagMovements) {
                $tagLower   = mb_strtolower($tagName);
                $shouldMatch = false;

                // Strategy 1: FinancialMappingEngine — map tag and descriptions to a canonical category
                $engineCategory = FinancialMappingEngine::mapToCategory($tagLower);
                if ($engineCategory !== null && mb_strtolower($engineCategory) === $categoryLower) {
                    $shouldMatch = true;
                }

                // Strategy 2: Also check individual movement descriptions through engine
                if (!$shouldMatch) {
                    foreach ($tagMovements as $mov) {
                        $desc = trim($mov['description'] ?? '');
                        if (!empty($desc) && $desc !== 'sin descripción') {
                            $ec = FinancialMappingEngine::mapToCategory(mb_strtolower($desc));
                            if ($ec !== null && mb_strtolower($ec) === $categoryLower) {
                                $shouldMatch = true;
                                break;
                            }
                        }
                    }
                }

                // Strategy 3: Direct substring match between tag words and category words
                if (!$shouldMatch) {
                    foreach ($categoryWords as $word) {
                        if (mb_strpos($tagLower, $word) !== false || mb_strpos($categoryLower, $tagLower) !== false) {
                            $shouldMatch = true;
                            break;
                        }
                    }
                }

                if ($shouldMatch && !in_array($tagName, $matchedTags)) {
                    $matchedTags[] = $tagName;

                    foreach ($tagMovements as $mov) {
                        $desc = trim($mov['description'] ?? '');
                        if (!empty($desc) && $desc !== 'sin descripción') {
                            $words = preg_split('/[\s\-_,\.]+/', mb_strtolower($desc), -1, PREG_SPLIT_NO_EMPTY);
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
                'tags'     => array_values($matchedTags),
                'keywords' => array_values($keywords),
            ];

            Log::info("Fallback matching para '{$categoryName}'", [
                'matched_tags'   => $matchedTags,
                'keywords_count' => count($keywords),
            ]);
        }

        Log::info('=== SIMPLE FALLBACK MATCHING COMPLETED ===');
        return $result;
    }
}
