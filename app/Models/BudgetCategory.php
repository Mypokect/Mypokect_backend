<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BudgetCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'budget_id',
        'name',
        'amount',
        'percentage',
        'reason',
        'linked_tags',
        'linked_tags_since',
        'order',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'linked_tags' => 'array',
        'linked_tags_since' => 'datetime',
    ];

    /**
     * Get the budget that owns this category.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Override toArray to always return linked_tags as simple array for API responses.
     * The rich format {"tags": [...], "keywords": [...]} is for internal use only.
     */
    public function toArray()
    {
        $array = parent::toArray();

        // Convert rich format to simple for API responses
        if (isset($array['linked_tags']) && is_array($array['linked_tags'])) {
            if (isset($array['linked_tags']['tags']) && is_array($array['linked_tags']['tags'])) {
                $array['linked_tags'] = $array['linked_tags']['tags'];
            }
        }

        return $array;
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
