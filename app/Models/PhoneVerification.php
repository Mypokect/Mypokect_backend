<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un código OTP emitido para verificar la propiedad de un teléfono.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $telefono Número en formato E.164 (+573001234567)
 * @property string $codigo Hash del OTP (nunca el valor en claro)
 * @property int $intentos Intentos de validación consumidos
 * @property bool $verificado
 * @property Carbon $expira_en
 * @property Carbon $enviado_en
 */
class PhoneVerification extends Model
{
    /** Intentos máximos de validación por código. */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'user_id',
        'telefono',
        'codigo',
        'intentos',
        'verificado',
        'expira_en',
        'enviado_en',
    ];

    /** El hash del código jamás debe salir en respuestas serializadas. */
    protected $hidden = ['codigo'];

    protected function casts(): array
    {
        return [
            'intentos' => 'integer',
            'verificado' => 'boolean',
            'expira_en' => 'datetime',
            'enviado_en' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Códigos que todavía pueden validarse (no verificados ni expirados). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('verificado', false)->where('expira_en', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expira_en->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verificado;
    }

    /** ¿Puede intentar validar de nuevo este código? */
    public function canRetry(): bool
    {
        return ! $this->isVerified()
            && ! $this->isExpired()
            && $this->intentos < self::MAX_ATTEMPTS;
    }

    /** Intentos de validación que le quedan a este código. */
    public function remainingAttempts(): int
    {
        return max(0, self::MAX_ATTEMPTS - $this->intentos);
    }
}
