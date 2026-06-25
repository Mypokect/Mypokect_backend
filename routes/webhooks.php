<?php

use App\Models\WebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks de pasarelas de pago
|--------------------------------------------------------------------------
| Prefijo: /api  (sin auth de usuario — se valida la FIRMA del proveedor)
|
| Patrón obligatorio:
|  1. Verificar firma del gateway (en el adapter correspondiente).
|  2. Idempotencia por (gateway, external_id): no procesar dos veces.
|  3. Encolar el procesamiento real (ProcessWebhookJob) y responder 200 rápido.
|
| Esqueleto F0: registra el webhook y responde 200. La activación de la
| suscripción se implementa en F3/F4 con los adapters reales.
*/

Route::post('/webhooks/{gateway}', function (string $gateway, Request $request) {
    $allowed = ['nequi', 'mercadopago'];
    if (! in_array($gateway, $allowed, true)) {
        return response()->json(['error' => 'unknown_gateway'], 404);
    }

    // Idempotencia básica (el external_id real lo extrae el adapter en F3/F4).
    $externalId = $request->input('id') ?? $request->input('data.id') ?? null;

    if ($externalId) {
        $already = WebhookLog::where('gateway', $gateway)
            ->where('external_id', $externalId)
            ->where('status', 'processed')
            ->exists();
        if ($already) {
            return response()->json(['ok' => true, 'idempotent' => true]);
        }
    }

    WebhookLog::create([
        'gateway'         => $gateway,
        'event_type'      => $request->input('type') ?? $request->input('action'),
        'external_id'     => $externalId,
        'signature_valid' => false, // se valida en el adapter (F3/F4)
        'payload'         => $request->all(),
        'status'          => 'received',
    ]);

    // TODO F3/F4: ProcessWebhookJob::dispatch($gateway, $request->all());

    return response()->json(['ok' => true]);
})->middleware('throttle:120,1');
