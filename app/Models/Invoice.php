<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'payment_id', 'number', 'status',
        'subtotal_cents', 'tax_cents', 'total_cents', 'currency', 'issued_at', 'pdf_path',
        'dian_cufe', 'dian_status',
        'billing_name', 'billing_doc_type', 'billing_doc_number', 'billing_email',
    ];

    protected $casts = [
        'subtotal_cents' => 'integer',
        'tax_cents'      => 'integer',
        'total_cents'    => 'integer',
        'issued_at'      => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'payment_id');
    }
}
