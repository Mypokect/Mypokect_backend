<?php

namespace App\Jobs;

use App\Models\WebhookLog;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Procesa (fuera del request) un webhook de Wompi ya firmado y registrado.
 * Marca el WebhookLog como processed/failed y delega en SubscriptionManager.
 */
class ProcessWompiWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public int $webhookLogId) {}

    public function handle(SubscriptionManager $manager): void
    {
        $log = WebhookLog::find($this->webhookLogId);
        if (! $log || $log->status === 'processed') {
            return;
        }

        $log->increment('attempts');

        try {
            $manager->handleWompiTransaction($log->payload ?? []);
            $log->update(['status' => 'processed', 'processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
            throw $e; // permite reintentos del worker
        }
    }
}
