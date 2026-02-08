<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'deleted_at' => 'datetime',
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
}
