<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionConfirmation extends Model
{
    protected $fillable = [
        'transaction_occurrence_id',
        'confirmed_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(TransactionOccurrence::class, 'transaction_occurrence_id');
    }
}
