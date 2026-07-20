<?php

namespace App\Services\PhoneVerification\Contracts;

/**
 * Canal por el que se entregan los OTP (SMS, WhatsApp, etc.).
 *
 * El servicio de verificación depende SOLO de esta interfaz: cambiar de
 * proveedor (Twilio → Hablame, SMS → WhatsApp) es escribir otra
 * implementación y re-bindearla en PhoneVerificationServiceProvider.
 */
interface NotificationProviderInterface
{
    /**
     * Entrega el código OTP al teléfono indicado.
     *
     * @param  string  $phone  Número en formato E.164 (+573001234567)
     * @param  string  $code  OTP en claro (solo transita hacia el proveedor)
     *
     * @throws \RuntimeException si el proveedor no pudo entregar el mensaje
     */
    public function sendOTP(string $phone, string $code): void;

    /**
     * Entrega un mensaje arbitrario (avisos de seguridad, notificaciones).
     *
     * @throws \RuntimeException si el proveedor no pudo entregar el mensaje
     */
    public function sendMessage(string $phone, string $message): void;
}
