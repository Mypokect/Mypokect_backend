<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TransactionOccurrence extends Model
{
    use HasFactory;

    protected $table = 'transaction_occurrences';

    protected $fillable = [
        'scheduled_transaction_id',
        'due_date',
        'is_paid',
    ];

    protected $casts = [
        'due_date' => 'date',
        'is_paid' => 'boolean',
    ];

    public function scheduledTransaction(): BelongsTo
    {
        return $this->belongsTo(ScheduledTransaction::class);
    }

    public function confirmation(): HasOne
    {
        return $this->hasOne(TransactionConfirmation::class);
    }
}
