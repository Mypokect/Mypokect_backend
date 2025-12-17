<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetCategory extends Model
{
    protected $fillable = [
        'budget_id',
        'name',
        'amount',
        'percentage',
        'reason',
        'order',
    ];

    protected $casts = [
        'amount' => 'float',
        'percentage' => 'float',
    ];

    /**
     * Get the budget that owns this category.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Calculate the percentage based on budget total.
     */
    public function recalculatePercentage(): void
    {
        if ($this->budget && $this->budget->total_amount > 0) {
            $this->percentage = round(($this->amount / $this->budget->total_amount) * 100, 2);
            $this->save();
        }
    }
}
