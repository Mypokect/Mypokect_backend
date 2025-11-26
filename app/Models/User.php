<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'fcm_token',
    ];

    protected $hidden = [ 'password', 'remember_token', ];
    protected $casts = [ 'email_verified_at' => 'datetime', 'password' => 'hashed', ];

    // Tus relaciones existentes
    public function movements(): HasMany { return $this->hasMany(Movement::class); }
    public function tags(): HasMany { return $this->hasMany(Tag::class); }

    // --- AÑADE ESTA NUEVA RELACIÓN ---
    public function scheduledTransactions(): HasMany
    {
        return $this->hasMany(ScheduledTransaction::class);
    }
}