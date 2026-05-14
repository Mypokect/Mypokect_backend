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
            $response = $this->callGroqAPI($prompt, 0.1, null, true);

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
ROL: Eres un extractor de transacciones financieras para una app colombiana de finanzas personales. Tu única función es convertir texto o voz coloquial en un JSON estructurado de una sola transacción.

CONTEXTO: El usuario habla en español colombiano informal. Puede usar jerga local, abreviaturas numéricas, o expresiones regionales. La entrada es siempre imprecisa — tu trabajo es interpretarla con máxima precisión.

OBJETIVO: Extraer exactamente UNA transacción del texto de entrada y devolverla como JSON puro.

CONDICIONES:
- NUNCA devuelvas Markdown, bloques de código, texto explicativo, ni nada fuera del JSON puro.
- El primer carácter de tu respuesta DEBE ser '{' y el último '}'.
- NUNCA uses null en ningún campo — usa los valores por defecto del template si no puedes extraer un valor.
- Si no puedes determinar el monto O la descripción con certeza → devuelve el template con campos vacíos/cero y error_type: "insufficient_data".
- El idioma de description y suggested_tag debe coincidir con el idioma del texto de entrada.
- is_business_expense: true SOLO si el gasto es claramente profesional/empresarial (ej. "compré materiales para el trabajo", "alquiler de oficina", "herramientas del negocio"). Default: false.

DICCIONARIO DE JERGA COLOMBIANA (aplica ANTES de parsear números):
| Término                                          | Significado                              |
|--------------------------------------------------|------------------------------------------|
| luca / lucas                                     | ×1.000 pesos ("50 lucas" = 50.000)       |
| palo / palos                                     | ×1.000.000 pesos ("2 palos" = 2.000.000) |
| k / mil                                          | ×1.000                                   |
| millón / millones                                | ×1.000.000                               |
| me cayó / me entró / me llegó / me pagaron       | recibí dinero → type: income             |
| me depositaron                                   | recibí dinero → type: income             |
| gasté / pagué / compré / me cobró / me salió     | dinero gastado → type: expense           |
| guardé / aparté / ahorré / le metí a             | ahorro → revisar metas                   |
| plin / nequi / daviplata / transfiya             | payment_method: digital                  |
| en efectivo / en billete / en plata física / cash | payment_method: cash                    |
| factura / rut / fe / factura electrónica         | has_invoice: true                        |
| quincena                                         | pago laboral de 15 días → rent_type: laboral |

CLASIFICACIÓN rent_type (solo ingresos — orden de prioridad):
1. laboral   → sueldo, salario, nómina, quincena, pago de nómina, contrato, pago laboral
2. honorarios → honorarios, consultoría, freelance, servicios profesionales, factura de servicios
3. capital   → arriendo, arrendamiento, intereses, rendimientos, dividendos, renta pasiva
4. comercial → venta, vendí, negocio, mercancía, local, factura de venta, caja del día
5. otros     → cualquier ingreso que no encaje en los anteriores

LÓGICA DE DECISIÓN:
- Verbo de gasto → type: "expense", rent_type: null
- Verbo de ingreso → type: "income", clasifica rent_type según tabla anterior
- Meta: SOLO pon suggested_tag "Meta: <nombre>" si las 3 condiciones son verdaderas: (1) no es expense, (2) hay verbo explícito de ahorro, (3) existe una meta con ese nombre en la lista de metas del usuario

EJEMPLOS:

Entrada: "gasté 50 lucas en el super"
Salida: {"amount":50000,"description":"Compra supermercado","type":"expense","payment_method":"digital","suggested_tag":"Mercado","has_invoice":false,"is_business_expense":false,"rent_type":null,"error_type":null}

Entrada: "me llegó la quincena, 2 palos y medio"
Salida: {"amount":2500000,"description":"Quincena laboral","type":"income","payment_method":"digital","suggested_tag":"Salario","has_invoice":false,"is_business_expense":false,"rent_type":"laboral","error_type":null}

Entrada: "compré materiales de construcción para el proyecto de la empresa, 800 lucas"
Salida: {"amount":800000,"description":"Materiales empresa","type":"expense","payment_method":"digital","suggested_tag":"Empresa","has_invoice":false,"is_business_expense":true,"rent_type":null,"error_type":null}

ENTRADA REAL:
Transcripción: "$transcription"
Tags del usuario: [$tagsList]
Metas del usuario: [$goalsList]

FORMATO DE SALIDA (JSON puro, sin texto adicional):
{"amount":0,"description":"","type":"expense","payment_method":"digital","suggested_tag":"","has_invoice":false,"is_business_expense":false,"rent_type":null,"error_type":null}
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
ROL: Eres un clasificador de transacciones financieras. Tu única función es asignar una etiqueta de categoría a una transacción.

CONTEXTO: El usuario lleva finanzas personales. Las etiquetas son categorías de gasto como "Comida", "Transporte", "Salud". Si el usuario ya tiene una etiqueta parecida, debe reutilizarse exactamente como está.

OBJETIVO: Devolver UNA SOLA palabra que clasifique esta transacción por tipo de servicio o actividad.

CONDICIONES:
- Devuelve SOLO la etiqueta. Sin JSON, sin explicaciones, sin puntuación, sin texto adicional.
- El idioma de la etiqueta debe coincidir con el idioma de la descripción.
- Clasifica por QUÉ se gastó el dinero (servicio/actividad), NUNCA por producto, marca u objeto.
- Si una etiqueta existente encaja semánticamente al 90%+ → úsala exactamente como está escrita.
- Si ninguna encaja → devuelve una nueva palabra genérica que describa la actividad.

EJEMPLOS:
| Descripción           | Etiqueta correcta | Etiqueta INCORRECTA |
|-----------------------|-------------------|---------------------|
| "almuerzo ejecutivo"  | Comida            | Almuerzo            |
| "taxi al aeropuerto"  | Transporte        | Taxi                |
| "camiseta adidas"     | Ropa              | Camiseta            |
| "consulta médico"     | Salud             | Médico              |

ENTRADA:
Descripción: "$description"
Monto: $amount
Etiquetas existentes: [$tagsList]
PROMPT;

        Log::debug('Prompt built', ['prompt_length' => strlen($prompt)]);

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
ROL: Eres un clasificador de etiquetas de gasto personal. Tu función es agrupar etiquetas del usuario dentro de categorías de presupuesto.

CONTEXTO: El usuario usa etiquetas para clasificar sus movimientos. Las descripciones de las transacciones revelan el tipo real de gasto y son más confiables que el nombre de la etiqueta.

OBJETIVO: Asignar cada etiqueta de gasto a la categoría de presupuesto que mejor la representa.

CONDICIONES:
- Las DESCRIPCIONES tienen mayor prioridad que el nombre de la etiqueta.
- Si una etiqueta tiene descripciones mixtas → asígnala a la categoría predominante.
- Si no hay descripciones → usa el nombre de la etiqueta para matching semántico.
- Cada etiqueta va a exactamente UNA categoría (la más específica).
- Si una etiqueta no encaja en ninguna categoría → no la incluyas en el output.
- TODAS las categorías deben aparecer en el JSON de salida (usa [] si ninguna etiqueta corresponde).
- Usa nombres EXACTOS de categorías (sensible a mayúsculas/minúsculas). Solo JSON, sin explicaciones.

EJEMPLOS:
- Tag "Varios" + descripciones ["almuerzo", "cena"]     → "Comida" (descripciones revelan el tipo real)
- Tag "Servicio" + descripciones ["hotel marriott"]     → "Hospedaje"
- Tag "Uber" + sin descripciones                        → "Transporte" (matching semántico por nombre)

ENTRADA:
Categorías: [$categoriesList]
Etiquetas con datos de gasto:
$tagsList

FORMATO DE SALIDA (JSON puro, valores = arrays de nombres de etiquetas):
{"Hospedaje": ["Hotel", "Servicio"], "Transporte": ["Uber"], "Comida": ["Varios"], "CategoriaVacia": []}
PROMPT;

        $response = $this->callGroqAPI($prompt, 0.2, 1000, true);

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
