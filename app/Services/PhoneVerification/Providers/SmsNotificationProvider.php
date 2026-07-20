<?php

namespace App\Services\PhoneVerification\Providers;

use App\Services\PhoneVerification\Contracts\NotificationProviderInterface;
use App\Services\Sms\SmsSender;

/**
 * Proveedor real: delega en SmsSender, que ya resuelve el driver según
 * SMS_DRIVER (log en local/staging, twilio en producción) y normaliza
 * números a E.164.
 */
class SmsNotificationProvider implements NotificationProviderInterface
{
    public function __construct(private readonly SmsSender $sms) {}

    public function sendOTP(string $phone, string $code): void
    {
        $this->sms->sendTo(
            $phone,
            null,
            "My Pokect: tu código de verificación es {$code}. Vence en 5 minutos. No lo compartas con nadie.",
        );
    }

    public function sendMessage(string $phone, string $message): void
    {
        $this->sms->sendTo($phone, null, $message);
    }
}
