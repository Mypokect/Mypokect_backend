<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Movement extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'amount',
        'description',
        'user_id',
        'tag_id',
        'payment_method',
        'has_invoice',
        'is_business_expense',
        'rent_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount'              => 'decimal:2',
        'has_invoice'         => 'boolean',
        'is_business_expense' => 'boolean',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    /**
     * Get the user that owns the movement.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tag associated with the movement.
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
