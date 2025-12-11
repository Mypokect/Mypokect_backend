<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReminderOccurrence extends Model
{
    use HasFactory;

    protected $fillable = [
        'reminder_id',
        'occurrence_date',
        'status',
    ];

    protected $casts = [
        'occurrence_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the reminder that owns the occurrence.
     */
    public function reminder()
    {
        return $this->belongsTo(Reminder::class);
    }
}
