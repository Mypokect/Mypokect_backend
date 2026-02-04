<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionOccurrence extends Model
{
    use HasFactory;

    protected $table = 'transaction_occurrences';

    protected $fillable = [
        // --- ¡AÑADE 'user_id' A LA LISTA! ---
        'user_id',
        'scheduled_transaction_id',
        'occurrence_date',
        'is_paid',
    ];

    protected $casts = [
        'occurrence_date' => 'date',
        'is_paid' => 'boolean',
    ];
}
