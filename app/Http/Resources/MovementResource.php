<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'type'                => $this->type,
            'amount'              => (float) ($this->amount ?? 0),
            'description'         => $this->description ?? '',
            'tag_name'            => $this->whenLoaded('tag', fn () => $this->tag?->name ?? '', ''),
            'payment_method'      => $this->payment_method ?? 'digital',
            'has_invoice'         => (bool) ($this->has_invoice ?? false),
            'is_business_expense' => (bool) ($this->is_business_expense ?? false),
            'rent_type'           => $this->rent_type ?? null,
            'created_at'          => $this->created_at->toIso8601String(),
        ];
    }
}
