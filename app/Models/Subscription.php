<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    public const ACTIVE_STATUSES = ['trialing', 'active'];

    protected $fillable = [
        'user_id', 'plan_id', 'status', 'gateway', 'gateway_subscription_id',
        'auto_renew', 'current_period_start', 'current_period_end', 'trial_ends_at',
        'grace_ends_at', 'renewal_reminded_at', 'canceled_at', 'cancel_at_period_end',
    ];

    protected $casts = [
        'auto_renew'           => 'boolean',
        'current_period_start' => 'datetime',
        'current_period_end'   => 'datetime',
        'trial_ends_at'        => 'datetime',
        'grace_ends_at'        => 'datetime',
        'renewal_reminded_at'  => 'datetime',
        'canceled_at'          => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    /** ¿Da acceso premium ahora mismo? (trialing o active, sin vencer) */
    public function isPremium(): bool
    {
        if (! in_array($this->status, self::ACTIVE_STATUSES, true)) {
            return false;
        }
        if ($this->current_period_end && $this->current_period_end->isPast()) {
            return false;
        }
        return true;
    }
}
