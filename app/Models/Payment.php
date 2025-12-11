<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'reminder_id',
        'paid_at',
        'amount_paid',
        'note',
        'receipt_path',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount_paid' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the reminder that owns the payment.
     */
    public function reminder()
    {
        return $this->belongsTo(Reminder::class);
    }
}
