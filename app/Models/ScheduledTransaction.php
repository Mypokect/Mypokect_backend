<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'amount', 'type', 'category', 'start_date',
        'recurrence_type', 'recurrence_interval', 'end_date', 'reminder_days_before',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function occurrences(): HasMany
    {
        return $this->hasMany(TransactionOccurrence::class);
    }
}
