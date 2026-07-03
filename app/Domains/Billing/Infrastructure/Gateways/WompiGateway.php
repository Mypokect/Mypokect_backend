<?php

namespace App\Domains\Billing\Infrastructure\Gateways;

use App\Domains\Billing\Domain\Contracts\PaymentGateway;
use App\Domains\Billing\Domain\Contracts\WebhookEvent;
use App\Models\BillingPayment;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adapter de Wompi (Bancolombia). Implementa el puerto PaymentGateway.
 *
 * Flujo de cobro (estilo Spotify, pago fuera de la app):
 *  1. createCheckout() construye una URL firmada de Web Checkout y registra un
 *     BillingPayment pendiente con una `reference` única.
 *  2. El usuario paga en checkout.wompi.co con CUALQUIER método.
 *  3. Wompi notifica por webhook (transaction.updated) -> verifyWebhook().
 *  4. SubscriptionManager activa la suscripción al recibir un pago aprobado.
 *
 * Recurrencia (híbrido): si el método es tarjeta/Nequi se guarda una fuente de
 * pago (payment_source) para auto-renovar; el resto renueva por checkout.
 */
class WompiGateway implements PaymentGateway
{
    private const CHECKOUT_URL = 'https://checkout.wompi.co/p/';

    /** Mapa de estados de Wompi -> estados normalizados del dominio. */
    private const STATUS_MAP = [
        'APPROVED' => 'approved',
        'DECLINED' => 'rejected',
        'VOIDED' => 'refunded',
        'ERROR' => 'rejected',
        'PENDING' => 'pending',
    ];

    public function createCheckout(Subscription $subscription, Plan $plan): array
    {
        $config = $this->config();
        $amountInCents = (int) $plan->price_cents; // price_cents = pesos*100 = amount-in-cents de Wompi
        $currency = 'COP';
        $reference = $this->buildReference($subscription, $plan);

        // Registra el intento de pago (pendiente) para mapear el webhook luego.
        BillingPayment::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'gateway' => 'wompi',
            'reference' => $reference,
            'amount_cents' => $amountInCents,
            'currency' => $currency,
            'status' => 'pending',
        ]);

        $signature = $this->integritySignature($reference, $amountInCents, $currency);

        $query = [
            'public-key' => $config['public_key'],
            'currency' => $currency,
            'amount-in-cents' => $amountInCents,
            'reference' => $reference,
            'signature:integrity' => $signature,
        ];

        // El WAF de Wompi (CloudFront) BLOQUEA con 403 los redirect-url hacia
        // localhost o IPs privadas. En dev se omite: el usuario ve el recibo en
        // Wompi y el estado se reconcilia por API en /subscription/status.
        if (! empty($config['redirect_url']) && ! self::isPrivateUrl($config['redirect_url'])) {
            $query['redirect-url'] = $config['redirect_url'];
        }

        // Email del comprador (opcional: auth es por teléfono). Si falta, Wompi lo pide.
        if ($email = $subscription->user?->email) {
            $query['customer-data:email'] = $email;
        }

        return [
            'checkout_url' => self::CHECKOUT_URL.'?'.http_build_query($query),
            'reference' => $reference,
            'payment_id' => $reference, // aún sin transaction id; se conoce en el webhook
        ];
    }

    public function verifyWebhook(Request $request): WebhookEvent
    {
        $config = $this->config();
        $signature = $request->input('signature', []);
        $properties = $signature['properties'] ?? [];
        $checksumProvided = $signature['checksum'] ?? $request->header('X-Event-Checksum');
        $timestamp = $request->input('timestamp');

        if (empty($properties) || ! $checksumProvided || $timestamp === null) {
            throw new RuntimeException('Wompi webhook sin firma.');
        }

        // Concatena los VALORES de las propiedades (en orden) + timestamp + events_secret.
        $concat = '';
        foreach ($properties as $path) {
            $concat .= (string) $request->input("data.{$path}", $request->input($path));
        }
        $concat .= (string) $timestamp;
        $concat .= (string) $config['events_secret'];

        $expected = hash('sha256', $concat);
        $valid = hash_equals($expected, (string) $checksumProvided);

        if (! $valid) {
            throw new RuntimeException('Checksum de webhook Wompi inválido.');
        }

        $transaction = $request->input('data.transaction', []);
        $wompiStatus = $transaction['status'] ?? 'PENDING';

        return new WebhookEvent(
            gateway: 'wompi',
            eventType: (string) $request->input('event', 'transaction.updated'),
            externalId: (string) ($transaction['id'] ?? ''),
            status: self::STATUS_MAP[$wompiStatus] ?? 'pending',
            signatureValid: true,
            payload: $request->all(),
        );
    }

    public function fetchPaymentStatus(string $externalId): string
    {
        $response = $this->client()
            ->withToken($this->config()['private_key'])
            ->get($this->apiBase()."/transactions/{$externalId}");

        $status = $response->json('data.status', 'PENDING');

        return self::STATUS_MAP[$status] ?? 'pending';
    }

    /**
     * Busca la transacción por referencia (respaldo cuando el webhook no puede
     * llegar, p.ej. desarrollo local sin túnel público). Devuelve el array
     * crudo de la transacción de Wompi o null si el usuario aún no paga.
     */
    public function fetchTransactionByReference(string $reference): ?array
    {
        $response = $this->client()
            ->withToken($this->config()['private_key'])
            ->get($this->apiBase().'/transactions', ['reference' => $reference]);

        return $response->json('data.0');
    }

    /** ¿URL de localhost / red privada? (rechazadas por el WAF de Wompi) */
    public static function isPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';

        return $host === 'localhost'
            || str_ends_with($host, '.local')
            || preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host) === 1;
    }

    // --- Recurrencia (fuentes de pago: tarjeta / Nequi) -------------------------

    /** Token de aceptación pre-firmado (obligatorio para crear fuentes de pago). */
    public function getAcceptanceToken(): string
    {
        $config = $this->config();
        $response = $this->client()
            ->get($this->apiBase().'/merchants/'.$config['public_key']);

        return (string) $response->json('data.presigned_acceptance.acceptance_token');
    }

    /**
     * Tokens de aceptación + permalinks (T&C y autorización de datos) para
     * mostrarlos al usuario antes de guardar una fuente de pago.
     */
    public function getAcceptance(): array
    {
        $config = $this->config();
        $data = (array) $this->client()
            ->get($this->apiBase().'/merchants/'.$config['public_key'])
            ->json('data', []);

        return [
            'acceptance' => $data['presigned_acceptance'] ?? null,
            'personal_data_auth' => $data['presigned_personal_data_auth'] ?? null,
        ];
    }

    /**
     * Flujo de débito automático: crea una fuente de pago reutilizable a partir
     * de un token tokenizado en el cliente, registra el BillingPayment pendiente
     * y ejecuta el primer cobro (recurrente). El resultado se concilia con
     * SubscriptionManager (síncrono si Wompi resuelve al instante; si queda
     * PENDING, lo cierra el webhook).
     *
     * @return array{reference:string, payment_source_id:int, source:array, transaction:array}
     */
    public function chargeWithToken(Subscription $subscription, Plan $plan, string $type, string $token, string $email): array
    {
        $source = $this->createPaymentSource($type, $token, $email);
        $sourceId = (int) ($source['id'] ?? 0);

        if ($sourceId <= 0) {
            throw new RuntimeException('No se pudo crear la fuente de pago en Wompi.');
        }

        $amountInCents = (int) $plan->price_cents;
        $reference = $this->buildReference($subscription, $plan);

        BillingPayment::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'gateway' => 'wompi',
            'reference' => $reference,
            'amount_cents' => $amountInCents,
            'currency' => 'COP',
            'status' => 'pending',
        ]);

        $tx = $this->chargePaymentSource($sourceId, $amountInCents, $reference, $email);

        return [
            'reference' => $reference,
            'payment_source_id' => $sourceId,
            'source' => $source,
            'transaction' => $tx,
        ];
    }

    /** Crea una fuente de pago reutilizable a partir de un token tokenizado. */
    public function createPaymentSource(string $type, string $token, string $email): array
    {
        $response = $this->client()
            ->withToken($this->config()['private_key'])
            ->post($this->apiBase().'/payment_sources', [
                'type' => $type, // CARD | NEQUI
                'token' => $token,
                'customer_email' => $email,
                'acceptance_token' => $this->getAcceptanceToken(),
            ]);

        return (array) $response->json('data', []);
    }

    /** Cobra una fuente de pago existente (renovación automática). */
    public function chargePaymentSource(int $paymentSourceId, int $amountInCents, string $reference, string $email): array
    {
        $response = $this->client()
            ->withToken($this->config()['private_key'])
            ->post($this->apiBase().'/transactions', [
                'amount_in_cents' => $amountInCents,
                'currency' => 'COP',
                'customer_email' => $email,
                'payment_source_id' => $paymentSourceId,
                'reference' => $reference,
                'recurrent' => true,
            ]);

        return (array) $response->json('data', []);
    }

    // --- Helpers ---------------------------------------------------------------

    private function integritySignature(string $reference, int $amountInCents, string $currency): string
    {
        $secret = $this->config()['integrity_secret'];

        return hash('sha256', $reference.$amountInCents.$currency.$secret);
    }

    private function buildReference(Subscription $subscription, Plan $plan): string
    {
        return 'sub'.$subscription->id.'-'.$plan->code.'-'.Str::lower(Str::random(12));
    }

    private function apiBase(): string
    {
        return $this->config()['environment'] === 'production'
            ? 'https://production.wompi.co/v1'
            : 'https://sandbox.wompi.co/v1';
    }

    private function client()
    {
        return Http::acceptJson()->timeout(20);
    }

    private function config(): array
    {
        $config = config('services.wompi');

        // Las llaves test/prod no se pueden mezclar: Wompi valida la firma con
        // el secreto del MISMO ambiente de la llave pública (error real que ya
        // pasó: prv_prod_ + pub_test_ → todo falla de forma confusa).
        $expected = ($config['environment'] ?? 'sandbox') === 'production' ? 'prod' : 'test';
        foreach (['public_key', 'private_key', 'events_secret', 'integrity_secret'] as $key) {
            $value = (string) ($config[$key] ?? '');
            if ($value !== '' && ! str_contains(substr($value, 0, 15), $expected)) {
                throw new RuntimeException(
                    "Llaves de Wompi mezcladas: WOMPI_ENV={$config['environment']} pero {$key} es del otro ambiente. Usa el juego completo del mismo ambiente."
                );
            }
        }

        return $config;
    }
}
