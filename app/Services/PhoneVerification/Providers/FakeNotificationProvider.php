<?php

namespace App\Services\PhoneVerification\Providers;

use App\Services\PhoneVerification\Contracts\NotificationProviderInterface;

/**
 * Proveedor en memoria para tests: no envía nada, registra lo "enviado"
 * para poder hacer aserciones. Bindéalo como singleton en el setUp del test
 * y recupéralo del contenedor para inspeccionarlo.
 */
class FakeNotificationProvider implements NotificationProviderInterface
{
    /** @var array<int, array{phone: string, code: string}> */
    public array $sentOtps = [];

    /** @var array<int, array{phone: string, message: string}> */
    public array $sentMessages = [];

    /** Si es true, simula un proveedor caído. */
    public bool $shouldFail = false;

    public function sendOTP(string $phone, string $code): void
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('Proveedor de mensajería no disponible (simulado).');
        }

        $this->sentOtps[] = ['phone' => $phone, 'code' => $code];
    }

    public function sendMessage(string $phone, string $message): void
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('Proveedor de mensajería no disponible (simulado).');
        }

        $this->sentMessages[] = ['phone' => $phone, 'message' => $message];
    }

    /** Último OTP "enviado" a un teléfono, o null si no se envió ninguno. */
    public function lastOtpFor(string $phone): ?string
    {
        foreach (array_reverse($this->sentOtps) as $sent) {
            if ($sent['phone'] === $phone) {
                return $sent['code'];
            }
        }

        return null;
    }
}
