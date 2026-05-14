<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reminder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'type',
        'amount',
        'category',
        'note',
        'due_date',
        'timezone',
        'recurrence',
        'recurrence_params',
        'notify_offset_minutes',
        'status',
        'last_notified_at',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'recurrence_params' => 'array',
        'notify_offset_minutes' => 'integer',
        'amount' => 'decimal:2',
        'last_notified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user that owns the reminder.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the occurrences for the reminder.
     */
    public function occurrences()
    {
        return $this->hasMany(ReminderOccurrence::class);
    }

    /**
     * Get the payments for the reminder.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Scope to filter reminders by date range.
     */
    public function scopeDateRange($query, $start, $end)
    {
        return $query->whereBetween('due_date', [$start, $end]);
    }

    /**
     * Scope to filter pending reminders.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Check if reminder is monthly recurring.
     */
    public function isMonthlyRecurring(): bool
    {
        return $this->recurrence === 'monthly';
    }

    /**
     * Get the notification datetime (due_date - offset).
     */
    public function getNotificationDateTime(): \DateTime
    {
        $notifyAt = clone $this->due_date;
        return $notifyAt->modify("-{$this->notify_offset_minutes} minutes");
    }
}
