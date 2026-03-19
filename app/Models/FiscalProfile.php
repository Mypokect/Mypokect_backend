<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalProfile extends Model
{
    protected $fillable = [
        'user_id',
        'year',
        'patrimonio',
        'dependientes',
        'deduc_salud',
        'deduc_vivienda',
        'retenciones',
    ];

    protected $casts = [
        'year'           => 'integer',
        'patrimonio'     => 'decimal:2',
        'dependientes'   => 'integer',
        'deduc_salud'    => 'decimal:2',
        'deduc_vivienda' => 'decimal:2',
        'retenciones'    => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
