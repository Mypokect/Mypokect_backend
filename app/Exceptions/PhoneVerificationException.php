<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Error de dominio del flujo de verificación de teléfono.
 *
 * Cada caso se construye con su constructor estático y sabe renderizarse a
 * JSON con el status HTTP correcto, así el controlador no necesita try/catch.
 */
class PhoneVerificationException extends Exception
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function throttled(int $availableInSeconds): self
    {
        return new self(
            "Ya te enviamos un código hace poco. Puedes pedir otro en {$availableInSeconds} segundos.",
            429,
        );
    }

    public static function alreadyVerified(): self
    {
        return new self('Ese teléfono ya está verificado.', 409);
    }

    public static function expired(): self
    {
        return new self('El código expiró o no existe. Solicita uno nuevo.', 422);
    }

    public static function invalidCode(int $remainingAttempts): self
    {
        return new self(
            "El código ingresado no es válido. Te quedan {$remainingAttempts} intentos.",
            422,
        );
    }

    public static function tooManyAttempts(): self
    {
        return new self('Demasiados intentos fallidos. Solicita un código nuevo.', 429);
    }

    public static function providerFailed(): self
    {
        return new self('No pudimos enviar el código en este momento. Intenta de nuevo en unos minutos.', 502);
    }

    /** Render automático cuando la excepción llega al handler de Laravel. */
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
        ], $this->status);
    }
}
