<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 3-layer hybrid parser: Cache → Rule Engine → LLM Fallback.
 *
 * Designed to handle ≥80% of Colombian Spanish financial messages without
 * any LLM call. LLM is invoked only when amount OR intent cannot be resolved
 * by rules, and uses an ultra-short (<80 token) prompt.
 */
class HybridTransactionParser
{
    // ── Config ─────────────────────────────────────────────────────────────────

    private const CACHE_TTL          = 86_400;          // 24 h
    private const CACHE_PREFIX       = 'txn:';
    private const CONFIDENCE_CUTOFF  = 0.70;
    private const LLM_MAX_TOKENS     = 80;
    private const LLM_TEMPERATURE    = 0.0;             // deterministic output
    private const LLM_MODEL          = 'llama-3.1-8b-instant';
    private const LLM_TIMEOUT        = 8;

    // ── Slang → multiplier (ordered: longest/most-specific first) ─────────────

    private const SLANG = [
        '/\bmedio\s+palo\b/i'                                    => 500_000,
        '/\b(\d+(?:[.,]\d+)?)\s*palos?\b/i'                     => 1_000_000,
        '/\bun\s+mill[oó]n\b/i'                                  => 1_000_000,
        '/\b(\d+(?:[.,]\d+)?)\s*mill[oó]nes?\b/i'               => 1_000_000,
        '/\b(\d+(?:[.,]\d+)?)\s*lucas?\b/i'                     => 1_000,
        '/\b(\d+(?:[.,]\d+)?)\s*k\b/i'                          => 1_000,
    ];

    // ── Intent keyword lists ───────────────────────────────────────────────────

    private const EXPENSE_KW = [
        'gasté','gaste','pagué','pague','compré','compre','transferí','transfiri',
        'mandé','mande','saqué','saque','envié','envie','presté','preste',
        'invertí','invertí','salió','salio','desembolsé','desembolse',
        'pago','compro','transfiero','gasto',
    ];

    private const INCOME_KW = [
        'llegó','llego','recibí','recibi','me pagaron','pagaron','ingresó','ingreso',
        'abonaron','depositaron','me transfirieron','cobré','cobre',
        'quincena','sueldo','salario','nómina','nomina',
        'me cayó','me cayo','ganó','gano','me dieron','dieron','entró','entro',
    ];

    // ── Payment method keywords ────────────────────────────────────────────────

    private const DIGITAL_KW = [
        'nequi','daviplata','transfiya','transferencia','tarjeta','app','online',
        'bancolombia','bbva','davivienda','digital','internet','banco',
    ];

    private const CASH_KW = [
        'efectivo','billetes','plata física','plata fisica','en mano','cash','físico','fisico',
    ];

    // ── Invoice keywords ───────────────────────────────────────────────────────

    private const INVOICE_KW = [
        'factura','fe','fce','iva','boleta','recibo electrónico',
        'factura electrónica','comprobante',
    ];

    // ── Business expense keywords ──────────────────────────────────────────────

    private const BUSINESS_KW = [
        'empresa','negocio','trabajo','cliente','deducible','oficina','proveedor','laboral',
    ];

    // ── rent_type classification (income only) ─────────────────────────────────

    private const RENT_TYPE = [
        'laboral'    => ['sueldo','salario','quincena','nómina','nomina','contrato'],
        'honorarios' => ['honorarios','freelance','consultoría','consultoria','servicios profesionales'],
        'capital'    => ['arriendo','intereses','dividendos','inversión','inversion','rendimientos','arrendamiento'],
        'comercial'  => ['venta','ventas','negocio','tienda','mercancía','mercancia','comercio'],
    ];

    // ── Tag extraction patterns ────────────────────────────────────────────────
    // Skip Spanish articles when capturing the noun.

    private const TAG_PATTERNS = [
        '/\ben\s+(?:el|la|los|las|un|una)?\s*([a-záéíóúñ]{3,})/i',
        '/\bpara\s+(?:el|la|los|las|un|una)?\s*([a-záéíóúñ]{3,})/i',
        '/\bde\s+(?:el|la|los|las|un|una)?\s*([a-záéíóúñ]{3,})/i',
    ];

    // ── Noise words stripped before description extraction ─────────────────────

    private const NOISE_WORDS = [
        'hoy','ayer','mañana','manana','me','se','fue','era','que','del','con',
        'por','un','una','hay','esta','estaba','le','lo','le','les',
    ];

    // ──────────────────────────────────────────────────────────────────────────

    private string $groqApiKey;
    private string $groqBaseUrl;

    public function __construct()
    {
        $this->groqApiKey  = config('services.groq.key', '');
        $this->groqBaseUrl = 'https://api.groq.com/openai/v1/chat/completions';
    }

    // ── PUBLIC ENTRY POINT ────────────────────────────────────────────────────

    /**
     * Parse a financial voice/text input using the 3-layer pipeline.
     *
     * Returns the same field shape as MovementAIService::normalizeMovementSuggestion().
     * Extra field `_source` = 'cache' | 'rules' | 'llm' | 'rules_partial'.
     *
     * @return array{
     *   amount: float,
     *   type: string,
     *   description: string,
     *   suggested_tag: string,
     *   payment_method: string,
     *   has_invoice: bool,
     *   is_business_expense: bool,
     *   rent_type: string|null,
     *   error_type: string|null,
     *   _source: string
     * }
     */
    public function parse(string $input, array $userTags = [], array $userGoals = []): array
    {
        $normalized = $this->normalize($input);
        $cacheKey   = self::CACHE_PREFIX . md5($normalized);

        // ── Layer 1: Cache ─────────────────────────────────────────────────
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('HybridParser cache hit', ['key' => $cacheKey]);
            return array_merge($cached, ['_source' => 'cache']);
        }

        // ── Layer 2: Rule engine ───────────────────────────────────────────
        $ruled = $this->runRuleEngine($normalized, $userTags, $userGoals);
        Log::debug('HybridParser rule result', [
            'confidence' => $ruled['_confidence'],
            'amount'     => $ruled['amount'],
            'type'       => $ruled['type'],
        ]);

        if ($ruled['_confidence'] >= self::CONFIDENCE_CUTOFF) {
            $clean = $this->finalize($ruled);
            Cache::put($cacheKey, $clean, self::CACHE_TTL);
            Log::info('HybridParser resolved by rules', ['amount' => $clean['amount'], 'type' => $clean['type']]);
            return array_merge($clean, ['_source' => 'rules']);
        }

        // ── Layer 3: LLM fallback ──────────────────────────────────────────
        Log::info('HybridParser LLM fallback triggered', [
            'confidence' => $ruled['_confidence'],
            'missing'    => $ruled['amount'] === 0.0 ? 'amount' : 'type',
        ]);
        $llm = $this->callLLM($normalized, $ruled);

        if ($llm !== null) {
            $merged = $this->merge($ruled, $llm);
            $clean  = $this->finalize($merged);
            Cache::put($cacheKey, $clean, self::CACHE_TTL);
            return array_merge($clean, ['_source' => 'llm']);
        }

        // Partial rules result — LLM also failed
        $clean = $this->finalize($ruled);
        return array_merge($clean, ['_source' => 'rules_partial']);
    }

    // ── LAYER 2: RULE ENGINE ──────────────────────────────────────────────────

    private function runRuleEngine(string $input, array $userTags, array $userGoals): array
    {
        [$amount,  $amountConf] = $this->extractAmount($input);
        [$type,    $intentConf] = $this->detectIntent($input);
        $description            = $this->extractDescription($input, $type);
        $tag                    = $this->extractTag($input, $userTags, $userGoals);
        $paymentMethod          = $this->detectPaymentMethod($input);
        $hasInvoice             = $this->detectKeywordMatch($input, self::INVOICE_KW);
        $isBusiness             = $this->detectKeywordMatch($input, self::BUSINESS_KW);
        $rentType               = $type === 'income' ? $this->detectRentType($input) : null;

        return [
            'amount'              => $amount,
            'type'                => $type,
            'description'         => $description,
            'suggested_tag'       => $tag,
            'payment_method'      => $paymentMethod,
            'has_invoice'         => $hasInvoice,
            'is_business_expense' => $isBusiness,
            'rent_type'           => $rentType,
            'error_type'          => null,
            '_confidence'         => min(1.0, $amountConf + $intentConf),
        ];
    }

    // ── Amount ────────────────────────────────────────────────────────────────

    private function extractAmount(string $input): array
    {
        // 1. Slang patterns (ordered: most specific first)
        foreach (self::SLANG as $pattern => $multiplier) {
            if (!preg_match($pattern, $input, $m)) {
                continue;
            }
            // Fixed-value patterns have no capture group (e.g. "medio palo", "un millón")
            if (!isset($m[1])) {
                return [(float) $multiplier, 0.4];
            }
            // Decimal slang: "1.5 palos" → 1500000
            $raw  = str_replace(',', '.', $m[1]);
            $base = (float) $raw;
            return [$base * $multiplier, 0.4];
        }

        // 2. Formatted number: "50,000" / "50.000" (thousands separator)
        if (preg_match('/\b(\d{1,3}(?:[.,]\d{3})+)\b/', $input, $m)) {
            return [(float) preg_replace('/[.,]/', '', $m[1]), 0.4];
        }

        // 3. Plain integer ≥ 4 digits (e.g. "5000")
        if (preg_match('/\b(\d{4,})\b/', $input, $m)) {
            return [(float) $m[1], 0.4];
        }

        // 4. Small plain integer — valid but ambiguous (e.g. "gasté 20")
        if (preg_match('/\b(\d{1,3})\b/', $input, $m)) {
            return [(float) $m[1], 0.2];
        }

        return [0.0, 0.0];
    }

    // ── Intent ────────────────────────────────────────────────────────────────

    private function detectIntent(string $input): array
    {
        $exp = 0;
        $inc = 0;

        foreach (self::EXPENSE_KW as $kw) {
            if (mb_stripos($input, $kw) !== false) {
                $exp++;
            }
        }
        foreach (self::INCOME_KW as $kw) {
            if (mb_stripos($input, $kw) !== false) {
                $inc++;
            }
        }

        if ($exp > 0 && $inc === 0) {
            return ['expense', 0.3];
        }
        if ($inc > 0 && $exp === 0) {
            return ['income', 0.3];
        }
        // Tie or nothing → default expense, but confidence NOT added
        return ['expense', 0.0];
    }

    // ── Description ───────────────────────────────────────────────────────────

    private function extractDescription(string $input, string $type): string
    {
        // Strip amount tokens to get cleaner phrase
        $clean = preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:k|palos?|lucas?|mill[oó]nes?)\b/i', '', $input);
        $clean = preg_replace('/\b\d{1,3}(?:[.,]\d{3})+\b/', '', $clean);
        $clean = preg_replace('/\b\d{4,}\b/', '', $clean);
        $clean = trim(preg_replace('/\s{2,}/', ' ', $clean));

        // Prefer "en X" / "para X" phrase as description
        foreach (self::TAG_PATTERNS as $pattern) {
            if (preg_match($pattern, $clean, $m)) {
                return ucfirst(mb_strtolower(trim($m[0])));
            }
        }

        // Strip noise words and return first 6 meaningful words
        $noisePattern = '/\b(' . implode('|', self::NOISE_WORDS) . ')\b/i';
        $words = array_filter(
            explode(' ', preg_replace($noisePattern, '', $clean)),
            fn($w) => strlen(trim($w)) > 2
        );

        $words = array_slice(array_values($words), 0, 6);
        return empty($words)
            ? ($type === 'income' ? 'Ingreso' : 'Gasto')
            : ucfirst(mb_strtolower(implode(' ', $words)));
    }

    // ── Tag ───────────────────────────────────────────────────────────────────

    private function extractTag(string $input, array $userTags, array $userGoals): string
    {
        // Check goal contribution first
        foreach ($userGoals as $goal) {
            if (mb_stripos($input, $goal) !== false) {
                return 'Meta: ' . $goal;
            }
        }

        // Try structural patterns: "en comida", "para gasolina"
        $rejectArticles = ['el','la','los','las','un','una','mis','mi','su'];
        foreach (self::TAG_PATTERNS as $pattern) {
            if (preg_match($pattern, $input, $m)) {
                $word = mb_strtolower(trim($m[1]));
                if (!in_array($word, $rejectArticles, true)) {
                    // Prefer matching an existing user tag (case-insensitive)
                    foreach ($userTags as $tag) {
                        if (mb_stripos($tag, $word) !== false || mb_stripos($word, mb_strtolower($tag)) !== false) {
                            return $tag;
                        }
                    }
                    // Map to canonical category before returning raw word
                    return FinancialMappingEngine::enrichTag($word, ucfirst($word));
                }
            }
        }

        // Last resort: scan the full input through the mapping engine
        $engineCategory = FinancialMappingEngine::mapToCategory($input);
        if ($engineCategory !== null) {
            // Prefer user tag that matches the canonical category
            foreach ($userTags as $tag) {
                if (mb_stripos($tag, $engineCategory) !== false) {
                    return $tag;
                }
            }
            return $engineCategory;
        }

        return 'General';
    }

    // ── Payment method ────────────────────────────────────────────────────────

    private function detectPaymentMethod(string $input): string
    {
        foreach (self::CASH_KW as $kw) {
            if (mb_stripos($input, $kw) !== false) {
                return 'cash';
            }
        }
        foreach (self::DIGITAL_KW as $kw) {
            if (mb_stripos($input, $kw) !== false) {
                return 'digital';
            }
        }
        return 'digital';
    }

    // ── Generic keyword match ─────────────────────────────────────────────────

    private function detectKeywordMatch(string $input, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (mb_stripos($input, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    // ── rent_type ─────────────────────────────────────────────────────────────

    private function detectRentType(string $input): string
    {
        foreach (self::RENT_TYPE as $type => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_stripos($input, $kw) !== false) {
                    return $type;
                }
            }
        }
        return 'otros';
    }

    // ── LAYER 3: LLM FALLBACK ─────────────────────────────────────────────────

    /**
     * Ultra-short LLM prompt (<80 tokens).
     * Only asks for what the rule engine could not resolve.
     */
    private function callLLM(string $input, array $partial): ?array
    {
        // Tell the model what we already know to get a shorter, focused answer
        $known   = [];
        $missing = [];

        if ($partial['amount'] > 0.0) {
            $known[] = '"amount":' . $partial['amount'];
        } else {
            $missing[] = 'amount';
        }
        if ($partial['_confidence'] >= 0.3) {
            $known[] = '"type":"' . $partial['type'] . '"';
        } else {
            $missing[] = 'type';
        }

        $knownHint = empty($known) ? '' : ' Known: {' . implode(',', $known) . '}.';

        $prompt = 'Input: "' . $input . '".'
            . $knownHint
            . ' Slang: k=1000,luca=1000,palo=1M.'
            . ' JSON only: {"amount":0,"type":"expense","description":"","suggested_tag":""}';

        Log::debug('HybridParser LLM prompt', [
            'tokens_approx' => (int) (strlen($prompt) / 4),
            'missing'       => $missing,
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->groqApiKey}",
                'Content-Type'  => 'application/json',
            ])->timeout(self::LLM_TIMEOUT)->post($this->groqBaseUrl, [
                'model'           => self::LLM_MODEL,
                'messages'        => [['role' => 'user', 'content' => $prompt]],
                'temperature'     => self::LLM_TEMPERATURE,
                'max_tokens'      => self::LLM_MAX_TOKENS,
                'response_format' => ['type' => 'json_object'],
            ]);

            if (!$response->successful()) {
                Log::warning('HybridParser LLM error', ['status' => $response->status()]);
                return null;
            }

            $content = $response['choices'][0]['message']['content'] ?? null;
            $decoded = $content ? json_decode($content, true) : null;

            return is_array($decoded) ? $decoded : null;

        } catch (\Exception $e) {
            Log::warning('HybridParser LLM exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ── Merge rules + LLM (rules always win for resolved fields) ─────────────

    private function merge(array $rules, array $llm): array
    {
        return [
            // Rules win if they resolved the field; LLM fills gaps
            'amount'              => $rules['amount'] !== 0.0
                                        ? $rules['amount']
                                        : (float) ($llm['amount'] ?? 0.0),
            'type'                => $rules['_confidence'] >= 0.3
                                        ? $rules['type']
                                        : ($llm['type'] ?? 'expense'),
            'description'         => $rules['description'] !== 'Gasto' && $rules['description'] !== 'Ingreso'
                                        ? $rules['description']
                                        : ($llm['description'] ?? $rules['description']),
            'suggested_tag'       => $rules['suggested_tag'] !== 'General'
                                        ? $rules['suggested_tag']
                                        : ($llm['suggested_tag'] ?? 'General'),
            'payment_method'      => $rules['payment_method'],
            'has_invoice'         => $rules['has_invoice'],
            'is_business_expense' => $rules['is_business_expense'],
            'rent_type'           => $rules['rent_type'],
            'error_type'          => null,
            '_confidence'         => 1.0,
        ];
    }

    // ── Finalize: strip internal fields, enforce types, set error_type ─────────

    private function finalize(array $result): array
    {
        unset($result['_confidence']);
        $amount = (float) ($result['amount'] ?? 0.0);
        return [
            'amount'              => $amount,
            'type'                => in_array($result['type'] ?? '', ['income', 'expense'], true)
                                        ? $result['type']
                                        : 'expense',
            'description'         => (string) ($result['description'] ?? 'Movimiento'),
            'suggested_tag'       => (string) ($result['suggested_tag'] ?? 'General'),
            'payment_method'      => in_array($result['payment_method'] ?? '', ['cash', 'digital'], true)
                                        ? $result['payment_method']
                                        : 'digital',
            'has_invoice'         => (bool) ($result['has_invoice'] ?? false),
            'is_business_expense' => (bool) ($result['is_business_expense'] ?? false),
            'rent_type'           => $result['rent_type'] ?? null,
            'error_type'          => $amount === 0.0 ? 'insufficient_data' : null,
        ];
    }

    // ── Normalize input for consistent cache keys ─────────────────────────────

    private function normalize(string $input): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $input)));
    }
}
