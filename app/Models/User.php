<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'password',
        'country_code',
        'phone',
        'fcm_token',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['password' => 'hashed'];

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
}
