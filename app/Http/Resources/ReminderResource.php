<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class ReminderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type ?? 'expense',
            'amount' => $this->amount ? (float) $this->amount : null,
            'category' => $this->category,
            'note' => $this->note,
            'due_date' => $this->due_date->toIso8601String(),
            'due_date_local' => Carbon::parse($this->due_date)
                ->setTimezone($this->timezone)
                ->toIso8601String(),
            'timezone' => $this->timezone,
            'recurrence' => $this->recurrence,
            'recurrence_params' => $this->recurrence_params,
            'notify_offset_minutes' => $this->notify_offset_minutes,
            'status' => $this->status,
            'is_recurring' => $this->isMonthlyRecurring(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
