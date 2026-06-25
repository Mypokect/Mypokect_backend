<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    protected $fillable = [
        'gateway', 'event_type', 'external_id', 'signature_valid',
        'payload', 'status', 'processed_at', 'attempts', 'error',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'payload'         => 'array',
        'processed_at'    => 'datetime',
        'attempts'        => 'integer',
    ];
}
