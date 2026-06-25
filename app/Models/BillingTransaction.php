<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Libro mayor de billing (append-only). No se actualiza ni se borra.
 */
class BillingTransaction extends Model
{
    public $timestamps = false; // solo created_at (useCurrent)

    protected $fillable = [
        'payment_id', 'type', 'amount_cents', 'balance_after', 'description',
    ];

    protected $casts = [
        'amount_cents'  => 'integer',
        'balance_after' => 'integer',
        'created_at'    => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'payment_id');
    }
}
