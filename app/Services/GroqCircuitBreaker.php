<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shared circuit breaker for all Groq API calls.
 *
 * When any service receives a 429, it calls GroqCircuitBreaker::trip() to block
 * all subsequent Groq calls until the rate-limit window expires. All services
 * call GroqCircuitBreaker::isOpen() before making a request.
 *
 * State lives in the Laravel cache so it is shared across processes/queues.
 */
final class GroqCircuitBreaker
{
    private const CACHE_KEY = 'groq_rate_limited';

    private const DEFAULT_TTL = 60; // seconds, used when Groq omits Retry-After

    /** Returns true when the breaker is open (Groq is rate-limiting). */
    public static function isOpen(): bool
    {
        return Cache::has(self::CACHE_KEY);
    }

    /**
     * Trip the breaker for $retryAfter seconds.
     * Call this whenever a Groq response has status 429.
     */
    public static function trip(int $retryAfter = self::DEFAULT_TTL): void
    {
        $expiresAt = now()->addSeconds($retryAfter);

        Cache::put(self::CACHE_KEY, true, $expiresAt);

        Log::warning('Groq circuit breaker tripped — all AI calls paused', [
            'retry_after_s' => $retryAfter,
            'retry_at' => $expiresAt->toTimeString(),
        ]);
    }
}
