<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lógica de dominio del billing, independiente de la pasarela.
 *
 * Activa/renueva suscripciones a partir de un pago de Wompi ya verificado.
 * Es idempotente: procesar el mismo evento dos veces no duplica efectos.
 */
class SubscriptionManager
{
    /** Wompi payment_method_type -> enum `method` de billing_payments. */
    private const METHOD_MAP = [
        'CARD' => 'card',
        'PSE' => 'pse',
        'NEQUI' => 'nequi',
        'BANCOLOMBIA_TRANSFER' => 'bancolombia_transfer',
        'BANCOLOMBIA_QR' => 'bancolombia_qr',
        'DAVIPLATA' => 'daviplata',
        'CASH' => 'cash',
        'CORRESPONSAL' => 'cash',
    ];

    /**
     * Procesa el payload de un evento transaction.updated de Wompi.
     * Devuelve true si actuó (o ya estaba aplicado), false si no encontró el pago.
     */
    public function handleWompiTransaction(array $payload): bool
    {
        $tx = $payload['data']['transaction'] ?? [];
        $reference = $tx['reference'] ?? null;
        $wompiStatus = $tx['status'] ?? 'PENDING';

        if (! $reference) {
            Log::warning('Wompi webhook sin reference', ['payload' => $payload]);

            return false;
        }

        $payment = BillingPayment::where('gateway', 'wompi')
            ->where('reference', $reference)
            ->first();

        if (! $payment) {
            Log::warning('Wompi webhook: BillingPayment no encontrado', ['reference' => $reference]);

            return false;
        }

        // Idempotencia: si ya quedó aprobado, no repetir.
        if ($payment->status === 'approved') {
            return true;
        }

        return match ($wompiStatus) {
            'APPROVED' => $this->approve($payment, $tx),
            'DECLINED', 'ERROR' => $this->markFailed($payment, $tx, 'rejected'),
            'VOIDED' => $this->markFailed($payment, $tx, 'refunded'),
            default => true, // PENDING u otros: nada que hacer aún
        };
    }

    private function approve(BillingPayment $payment, array $tx): bool
    {
        return DB::transaction(function () use ($payment, $tx) {
            $methodType = $tx['payment_method_type'] ?? null;

            $payment->update([
                'status' => 'approved',
                'gateway_payment_id' => $tx['id'] ?? null,
                'method' => self::METHOD_MAP[$methodType] ?? null,
                'paid_at' => now(),
                'raw_response' => $tx,
            ]);

            // Libro mayor (append-only)
            BillingTransaction::create([
                'payment_id' => $payment->id,
                'type' => 'charge',
                'amount_cents' => $payment->amount_cents,
                'description' => 'Pago de suscripción Wompi '.($tx['id'] ?? ''),
            ]);

            // Comprobante
            Invoice::firstOrCreate(
                ['payment_id' => $payment->id],
                [
                    'user_id' => $payment->user_id,
                    'number' => 'WMP-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                    'status' => 'paid',
                    'subtotal_cents' => $payment->amount_cents,
                    'total_cents' => $payment->amount_cents,
                    'currency' => $payment->currency,
                    'issued_at' => now(),
                    'billing_email' => $payment->user?->email,
                ]
            );

            $this->activateSubscription($payment, $tx);

            return true;
        });
    }

    private function activateSubscription(BillingPayment $payment, array $tx): void
    {
        $subscription = $payment->subscription;
        if (! $subscription) {
            return;
        }

        $plan = $payment->plan ?? $subscription->plan;
        $start = Carbon::now();
        $end = match ($plan?->interval) {
            'month' => $start->copy()->addMonthNoOverflow(),
            'year' => $start->copy()->addYear(),
            default => null,
        };

        $subscription->update([
            'status' => 'active',
            'gateway' => 'wompi',
            // Si Wompi devolvió una fuente de pago tokenizada, la guardamos para auto-renovar.
            'gateway_subscription_id' => $tx['payment_source_id'] ?? $subscription->gateway_subscription_id,
            'current_period_start' => $start,
            'current_period_end' => $end,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ]);
    }

    private function markFailed(BillingPayment $payment, array $tx, string $status): bool
    {
        $payment->update([
            'status' => $status,
            'gateway_payment_id' => $tx['id'] ?? $payment->gateway_payment_id,
            'raw_response' => $tx,
        ]);

        if ($status === 'refunded' && $subscription = $payment->subscription) {
            $subscription->update(['status' => 'canceled', 'canceled_at' => now()]);
        }

        return true;
    }
}
