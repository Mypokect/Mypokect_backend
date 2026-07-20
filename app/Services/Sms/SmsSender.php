<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Envío de SMS transaccionales (códigos de verificación).
 *
 * Driver según SMS_DRIVER en .env:
 *  - log    : no envía nada, solo deja rastro en el log (local/staging/tests).
 *  - twilio : envío real vía la API REST de Twilio (producción).
 *
 * Para agregar otro proveedor (Hablame, LabsMobile, etc.) basta con añadir
 * un caso al match de send() y sus credenciales en config/services.php.
 */
class SmsSender
{
    /**
     * Envía el código de verificación al teléfono del usuario.
     */
    public function sendVerificationCode(string $phone, ?string $countryCode, string $code, int $ttlSeconds): void
    {
        if ($ttlSeconds < 60) {
            $message = "My Pokect: tu código es {$code}. Vence en {$ttlSeconds} segundos. No lo compartas con nadie.";
        } else {
            $minutes = intdiv($ttlSeconds, 60);
            $message = "My Pokect: tu código de verificación es {$code}. Vence en {$minutes} minutos. No lo compartas con nadie.";
        }

        $this->send($this->toE164($phone, $countryCode), $message);
    }

    /**
     * Envía un mensaje arbitrario normalizando el número a E.164.
     */
    public function sendTo(string $phone, ?string $countryCode, string $message): void
    {
        $this->send($this->toE164($phone, $countryCode), $message);
    }

    public function send(string $to, string $message): void
    {
        $driver = (string) config('services.sms.driver', 'log');

        match ($driver) {
            'twilio' => $this->sendViaTwilio($to, $message),
            // El cuerpo contiene el código (una credencial): nunca va al log.
            default => Log::info('SMS simulado (SMS_DRIVER=log), no se envió nada', ['to' => $to]),
        };
    }

    private function sendViaTwilio(string $to, string $message): void
    {
        $sid = (string) config('services.sms.twilio.sid');
        $token = (string) config('services.sms.twilio.token');
        $from = (string) config('services.sms.twilio.from');

        if ($sid === '' || $token === '' || $from === '') {
            throw new RuntimeException('Twilio no está configurado: define TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN y TWILIO_FROM en el .env.');
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $to,
                'From' => $from,
                'Body' => $message,
            ]);

        if ($response->failed()) {
            Log::error('Falló el envío de SMS por Twilio', [
                'to' => $to,
                'status' => $response->status(),
                'twilio_code' => $response->json('code'),
                'twilio_message' => $response->json('message'),
            ]);

            throw new RuntimeException('No pudimos enviar el código por SMS. Intenta de nuevo en unos minutos.');
        }
    }

    /**
     * Normaliza a formato E.164 (+573001234567). `country_code` llega como
     * ISO ('CO') desde las apps o como prefijo ('+57') en registros antiguos.
     */
    private function toE164(string $phone, ?string $countryCode): string
    {
        $phone = preg_replace('/[\s\-().]/', '', trim($phone)) ?? '';

        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        $cc = strtoupper(trim((string) $countryCode));

        if (str_starts_with($cc, '+')) {
            return $cc.$phone;
        }

        $dialCodes = ['CO' => '57', 'US' => '1', 'MX' => '52'];
        $dial = $dialCodes[$cc] ?? '57'; // Colombia por defecto

        return '+'.$dial.$phone;
    }
}
