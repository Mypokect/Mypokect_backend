<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Falló un intento de verificación (código inválido, expirado o bloqueado).
 * Útil para alertas de seguridad y detección de fuerza bruta.
 */
class PhoneVerificationFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $telefono,
        public readonly string $reason,
        public readonly int $intentos = 0,
    ) {}
}
