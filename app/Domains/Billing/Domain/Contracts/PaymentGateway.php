<?php

namespace App\Domains\Billing\Domain\Contracts;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\Request;

/**
 * Puerto (hexagonal) que abstrae cualquier pasarela de pago.
 *
 * Adapters concretos viven en App\Domains\Billing\Infrastructure\Gateways:
 *   - NequiGateway
 *   - MercadoPagoGateway
 *
 * La lógica de negocio (activar/renovar suscripción) NO depende de qué gateway
 * se use: solo conoce este contrato.
 */
interface PaymentGateway
{
    /**
     * Inicia un checkout para una suscripción/plan.
     *
     * @return array{checkout_url?: string, qr?: string, payment_id: string}
     */
    public function createCheckout(Subscription $subscription, Plan $plan): array;

    /**
     * Verifica la firma del webhook y lo normaliza a un evento del dominio.
     * Debe lanzar excepción si la firma es inválida.
     */
    public function verifyWebhook(Request $request): WebhookEvent;

    /**
     * Consulta el estado real de un pago en el gateway (respaldo del webhook).
     *
     * @return string  pending|approved|rejected|refunded
     */
    public function fetchPaymentStatus(string $externalId): string;
}
