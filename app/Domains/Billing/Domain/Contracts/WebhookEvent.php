<?php

namespace App\Domains\Billing\Domain\Contracts;

/**
 * Evento de webhook normalizado, independiente del gateway.
 */
final class WebhookEvent
{
    public function __construct(
        public readonly string $gateway,
        public readonly string $eventType,
        public readonly string $externalId,   // id del pago/preferencia en el gateway
        public readonly string $status,        // pending|approved|rejected|refunded
        public readonly bool $signatureValid,
        public readonly array $payload = [],
    ) {}

    public function toArray(): array
    {
        return [
            'gateway'         => $this->gateway,
            'event_type'      => $this->eventType,
            'external_id'     => $this->externalId,
            'status'          => $this->status,
            'signature_valid' => $this->signatureValid,
            'payload'         => $this->payload,
        ];
    }
}
