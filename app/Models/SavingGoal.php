<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SavingGoal extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'target_amount',
        'deadline',
        'color',
        'emoji',
        'status',
        'money_location',
        'cuenta_asociada',
        'is_digital',
        'location_name',
    ];

    public const MONEY_LOCATIONS = [
        'Efectivo',
        'Banco',
        'Nequi/Daviplata',
        'Alcancía',
        'Inversión',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'deadline' => 'date',
        'deleted_at' => 'datetime',
        'is_digital' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(GoalContribution::class, 'goal_id', 'id');
    }
}
