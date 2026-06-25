<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pago de suscripción (tabla billing_payments).
 * No confundir con App\Models\Payment (recibos de recordatorios).
 */
class BillingPayment extends Model
{
    use HasFactory;

    protected $table = 'billing_payments';

    protected $fillable = [
        'user_id', 'subscription_id', 'plan_id', 'gateway', 'gateway_payment_id',
        'amount_cents', 'currency', 'status', 'method', 'paid_at', 'raw_response',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at'      => 'datetime',
        'raw_response' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BillingTransaction::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'id', 'payment_id');
    }
}
