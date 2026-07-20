<?php

namespace App\Http\Resources\PhoneVerification;

use App\Models\PhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respuesta de POST /api/phone/send-code y /api/phone/resend.
 * Nunca expone el código; solo metadatos útiles para la UI.
 *
 * @property-read PhoneVerification $resource
 */
class CodeSentResource extends JsonResource
{
    /** Sin envoltorio "data": el contrato es plano. */
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'success' => true,
            'message' => 'Código enviado',
            'expira_en' => $this->resource->expira_en->toIso8601String(),
            'reintento_en_segundos' => 60,
        ];
    }

    /**
     * Esto es una acción ("enviar código"), no la creación de un recurso
     * direccionable: siempre 200, aunque el modelo tenga wasRecentlyCreated
     * true (Laravel pondría 201 automáticamente si no se fuerza aquí).
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200);
    }
}
