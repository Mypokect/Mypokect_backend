<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Budget extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'total_amount',
        'mode', // 'manual' or 'ai'
        'language',
        'plan_type', // 'travel', 'event', 'party', 'purchase', 'project', 'other'
        'status', // 'draft', 'active', 'archived'
        'period', // 'weekly', 'biweekly', 'monthly', 'custom'
        'date_from',
        'date_to',
        'suggested_tags_cache', // JSON: último resultado de la IA de sugerencias de tags
        'suggested_tags_hash',  // MD5: hash de los datos de gasto → detecta si cambió algo
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'deleted_at' => 'datetime',
        'date_from' => 'date',
        'date_to' => 'date',
        'suggested_tags_cache' => 'array', // Laravel deserializa JSON automáticamente
    ];

    /**
     * Get the user that owns the budget.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all categories for this budget.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(BudgetCategory::class);
    }

    /**
     * Calculate the sum of all categories.
     */
    public function getCategoriesTotal(): float
    {
        return $this->categories()->sum('amount');
    }

    /**
     * Check if budget is valid (categories sum equals total_amount).
     */
    public function isValid(): bool
    {
        return abs($this->getCategoriesTotal() - $this->total_amount) < 0.01;
    }

    /**
     * Calculate start/end dates for the current period based on this budget's period type.
     */
    public function calculateCurrentPeriodDates(): array
    {
        $now = Carbon::now();

        switch ($this->period) {
            case 'weekly':
                return ['from' => $now->copy()->startOfDay(), 'to' => $now->copy()->addWeek()->startOfDay()];
            case 'biweekly':
                return ['from' => $now->copy()->startOfDay(), 'to' => $now->copy()->addDays(15)->startOfDay()];
            case 'monthly':
                return ['from' => $now->copy()->startOfDay(), 'to' => $now->copy()->addMonth()->startOfDay()];
            case 'custom':
            default:
                $duration = $this->date_from && $this->date_to
                    ? Carbon::parse($this->date_from)->diffInDays(Carbon::parse($this->date_to))
                    : 30;

                return ['from' => $now->copy(), 'to' => $now->copy()->addDays($duration)];
        }
    }
}
