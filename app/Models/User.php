<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'locale',
        'password',
        'country_code',
        'phone',
        'fcm_token',
        'savings_mode_pct',
        'savings_mode_amount',
        'has_active_challenge',
        'challenge_savings_balance',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password'                  => 'hashed',
        'email_verified_at'         => 'datetime',
        'has_active_challenge'      => 'boolean',
        'savings_mode_pct'          => 'float',
        'savings_mode_amount'       => 'float',
        'challenge_savings_balance' => 'float',
    ];

    // Tus relaciones existentes
    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function savingGoals(): HasMany
    {
        return $this->hasMany(SavingGoal::class);
    }

    public function scheduledTransactions(): HasMany
    {
        return $this->hasMany(ScheduledTransaction::class);
    }

    public function goalContributions(): HasMany
    {
        return $this->hasMany(GoalContribution::class);
    }

    public function fiscalProfiles(): HasMany
    {
        return $this->hasMany(FiscalProfile::class);
    }

    // --- SaaS / Billing ---

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** La suscripción más reciente (fuente de verdad del estado de plan). */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /** ¿El usuario tiene acceso premium ahora mismo? */
    public function isPremium(): bool
    {
        return (bool) $this->activeSubscription?->isPremium();
    }
}
