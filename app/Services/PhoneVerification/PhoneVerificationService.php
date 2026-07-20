<?php

namespace App\Services\PhoneVerification;

use App\Events\PhoneVerificationFailed;
use App\Events\PhoneVerificationRequested;
use App\Events\PhoneVerified;
use App\Exceptions\PhoneVerificationException;
use App\Models\PhoneVerification;
use App\Services\PhoneVerification\Contracts\NotificationProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Orquesta el ciclo de vida completo del OTP de verificación de teléfono:
 * generación, envío (vía proveedor desacoplado), reenvío con cooldown,
 * validación con límite de intentos y marcado como verificado.
 *
 * Reglas:
 *  - OTP de 6 dígitos generado con random_int(), almacenado SOLO como hash.
 *  - Vigencia de 5 minutos; al emitir uno nuevo, los anteriores se invalidan.
 *  - Máximo 1 envío por minuto por teléfono (RateLimiter de Laravel).
 *  - Máximo 5 intentos de validación por código.
 */
class PhoneVerificationService
{
    private const CODE_TTL_MINUTES = 5;

    private const SEND_COOLDOWN_SECONDS = 60;

    public function __construct(private readonly NotificationProviderInterface $provider) {}

    /**
     * Genera un OTP nuevo, invalida los anteriores y lo envía al teléfono.
     *
     * @param  string  $telefono  Número en formato E.164
     * @param  int|null  $userId  Usuario autenticado, si lo hay
     *
     * @throws PhoneVerificationException si el teléfono ya está verificado,
     *                                    el cooldown sigue activo o el proveedor no pudo enviar
     */
    public function sendCode(string $telefono, ?int $userId = null): PhoneVerification
    {
        if (PhoneVerification::query()->where('telefono', $telefono)->where('verificado', true)->exists()) {
            throw PhoneVerificationException::alreadyVerified();
        }

        $throttleKey = $this->sendThrottleKey($telefono);

        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            Log::warning('Verificación de teléfono: envío bloqueado por cooldown', ['telefono' => $telefono]);

            throw PhoneVerificationException::throttled(RateLimiter::availableIn($throttleKey));
        }

        RateLimiter::hit($throttleKey, self::SEND_COOLDOWN_SECONDS);

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        /** @var PhoneVerification $verification */
        $verification = DB::transaction(function () use ($telefono, $userId, $otp) {
            // Un solo código vigente a la vez: los anteriores expiran ya.
            PhoneVerification::query()
                ->where('telefono', $telefono)
                ->active()
                ->update(['expira_en' => now()]);

            return PhoneVerification::create([
                'user_id' => $userId,
                'telefono' => $telefono,
                'codigo' => Hash::make($otp),
                'intentos' => 0,
                'verificado' => false,
                'expira_en' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'enviado_en' => now(),
            ]);
        });

        try {
            $this->provider->sendOTP($telefono, $otp);
        } catch (\Throwable $e) {
            // Si no se entregó, el código no sirve y el usuario debe poder
            // reintentar de inmediato: se revierte el registro y el cooldown.
            $verification->delete();
            RateLimiter::clear($throttleKey);
            Log::error('Verificación de teléfono: fallo del proveedor', [
                'telefono' => $telefono,
                'error' => $e->getMessage(),
            ]);

            throw PhoneVerificationException::providerFailed();
        }

        PhoneVerificationRequested::dispatch($verification);
        Log::info('Verificación de teléfono: código enviado', [
            'telefono' => $telefono,
            'user_id' => $userId,
            'expira_en' => $verification->expira_en->toIso8601String(),
        ]);

        return $verification;
    }

    /**
     * Reenvía un código nuevo respetando el mismo cooldown de 1 minuto.
     *
     * @throws PhoneVerificationException
     */
    public function resendCode(string $telefono, ?int $userId = null): PhoneVerification
    {
        return $this->sendCode($telefono, $userId);
    }

    /**
     * Valida el OTP contra el último código activo del teléfono.
     *
     * @throws PhoneVerificationException si el código expiró, no coincide o
     *                                    se agotaron los intentos
     */
    public function verify(string $telefono, string $codigo): PhoneVerification
    {
        $verification = PhoneVerification::query()
            ->where('telefono', $telefono)
            ->where('verificado', false)
            ->latest('enviado_en')
            ->first();

        if ($verification === null || $verification->isExpired()) {
            $this->reportFailure($telefono, 'expired', $verification?->intentos ?? 0);

            throw PhoneVerificationException::expired();
        }

        if (! $verification->canRetry()) {
            $this->reportFailure($telefono, 'too_many_attempts', $verification->intentos);

            throw PhoneVerificationException::tooManyAttempts();
        }

        if (! Hash::check($codigo, $verification->codigo)) {
            $verification->increment('intentos');
            $this->reportFailure($telefono, 'invalid_code', $verification->intentos);

            if (! $verification->canRetry()) {
                throw PhoneVerificationException::tooManyAttempts();
            }

            throw PhoneVerificationException::invalidCode($verification->remainingAttempts());
        }

        $verification->forceFill(['verificado' => true])->save();

        // Verificado: libera el cooldown de envío para futuros flujos.
        RateLimiter::clear($this->sendThrottleKey($telefono));

        PhoneVerified::dispatch($verification);
        Log::info('Verificación de teléfono: teléfono verificado', [
            'telefono' => $telefono,
            'user_id' => $verification->user_id,
        ]);

        return $verification;
    }

    /** Registra el fallo como evento de seguridad y lo emite. */
    private function reportFailure(string $telefono, string $reason, int $intentos): void
    {
        PhoneVerificationFailed::dispatch($telefono, $reason, $intentos);
        Log::warning('Verificación de teléfono: intento fallido', [
            'telefono' => $telefono,
            'reason' => $reason,
            'intentos' => $intentos,
        ]);
    }

    private function sendThrottleKey(string $telefono): string
    {
        return 'phone_verification_send:'.$telefono;
    }
}
