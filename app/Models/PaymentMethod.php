<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Método de pago guardado (para débito automático). El `token` es la fuente de
 * pago reutilizable de Wompi (payment_source_id); nunca almacenamos datos de la
 * tarjeta: la tokenización ocurre en el cliente contra Wompi.
 */
class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'gateway', 'type', 'token', 'brand',
        'last_four', 'holder_masked', 'is_default', 'expires_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'expires_at' => 'date',
    ];

    protected $hidden = ['token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
