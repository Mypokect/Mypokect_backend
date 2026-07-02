<?php

use App\Domains\Billing\Domain\Contracts\PaymentGateway;
use App\Jobs\ProcessWompiWebhookJob;
use App\Models\WebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks de pasarelas de pago (Wompi)
|--------------------------------------------------------------------------
| Prefijo: /api  (sin auth de usuario — se valida la FIRMA del proveedor)
|
| Patrón:
|  1. Verificar la firma (checksum) del evento en el adapter WompiGateway.
|  2. Registrar SIEMPRE el evento (auditoría), marcando signature_valid.
|  3. Idempotencia por (gateway, external_id): no procesar dos veces.
|  4. Encolar el procesamiento real (ProcessWompiWebhookJob) y responder 200 rápido.
|
| Wompi reintenta si no recibe 2xx, por eso respondemos 200 incluso ante
| firmas inválidas (se registran como no procesables, no se actúa sobre ellas).
*/

Route::post('/webhooks/{gateway}', function (string $gateway, Request $request, PaymentGateway $paymentGateway) {
    if ($gateway !== 'wompi') {
        return response()->json(['error' => 'unknown_gateway'], 404);
    }

    // El id de la transacción de Wompi viaja en data.transaction.id
    $externalId = $request->input('data.transaction.id') ?? $request->input('data.id') ?? null;
    $eventType = $request->input('event'); // p.ej. "transaction.updated"

    // Idempotencia: si ya procesamos este evento, no repetir.
    if ($externalId) {
        $already = WebhookLog::where('gateway', $gateway)
            ->where('external_id', $externalId)
            ->where('status', 'processed')
            ->exists();
        if ($already) {
            return response()->json(['ok' => true, 'idempotent' => true]);
        }
    }

    // Verifica la firma del evento (checksum). No lanza: devuelve el flag.
    $signatureValid = false;
    try {
        $event = $paymentGateway->verifyWebhook($request);
        $signatureValid = $event->signatureValid;
    } catch (\Throwable $e) {
        $signatureValid = false;
    }

    $log = WebhookLog::create([
        'gateway'         => $gateway,
        'event_type'      => $eventType,
        'external_id'     => $externalId,
        'signature_valid' => $signatureValid,
        'payload'         => $request->all(),
        'status'          => 'received',
    ]);

    // Solo encolamos el procesamiento si la firma es válida.
    if ($signatureValid) {
        ProcessWompiWebhookJob::dispatch($log->id);
    } else {
        $log->update(['status' => 'ignored', 'error' => 'invalid_or_missing_signature']);
    }

    return response()->json(['ok' => true]);
})->middleware('throttle:120,1');
