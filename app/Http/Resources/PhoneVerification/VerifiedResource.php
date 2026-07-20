<?php

namespace App\Http\Resources\PhoneVerification;

use App\Models\PhoneVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respuesta de POST /api/phone/verify cuando el código es correcto.
 *
 * @property-read PhoneVerification $resource
 */
class VerifiedResource extends JsonResource
{
    /** Sin envoltorio "data": el contrato es plano. */
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'verified' => true,
            'telefono' => $this->resource->telefono,
            'verificado_en' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
