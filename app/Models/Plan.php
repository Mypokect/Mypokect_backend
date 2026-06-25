<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'description', 'price_cents', 'currency',
        'interval', 'trial_days', 'features', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'trial_days'  => 'integer',
        'features'    => 'array',
        'is_active'   => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Precio en pesos (price_cents / 100). */
    public function priceInCop(): float
    {
        return $this->price_cents / 100;
    }

    public function isFree(): bool
    {
        return $this->price_cents === 0;
    }
}
