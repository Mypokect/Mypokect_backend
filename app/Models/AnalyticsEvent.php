<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento de analítica de producto (tabla analytics_events).
 * Hoy registra `page_view` por sección desde la web; el panel admin los
 * agrega para mostrar el tráfico de cada parte de la aplicación.
 */
class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'anon_id', 'event', 'properties', 'session_id', 'occurred_at',
    ];

    protected $casts = [
        'properties'  => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
