<?php

namespace App\Models;

use App\Mail\PasswordResetMail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Mail;

// A person who can log in: only OWNER or CASHIER (table user_account).
class UserAccount extends Authenticatable
{
    protected $table = 'user_account';
    protected $primaryKey = 'user_id';
    public $timestamps = false; // the table has its own created_at with a DB default

    protected $guarded = [];

    // Never send the password hash to a view or JSON.
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    // Laravel's login checks the password against this column.
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    // The table has no remember_token column, so "remember me" is turned off.
    public function getRememberTokenName(): string
    {
        return '';
    }

    // Called by Laravel's password broker with the PLAIN token (only the hash is saved in the database).
    // Sends our pink email instead of Laravel's default one.
    public function sendPasswordResetNotification($token): void
    {
        $url = route('password.reset', ['token' => $token, 'email' => $this->email]);

        Mail::to($this->email)->send(new PasswordResetMail($url));
    }

    public function isOwner(): bool
    {
        return $this->role === 'OWNER';
    }

    // Sales this user rang up as cashier.
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'user_id', 'user_id');
    }

    // Sales this owner voided.
    public function voidedSales(): HasMany
    {
        return $this->hasMany(Sale::class, 'voided_by', 'user_id');
    }

    public function inventoryHistory(): HasMany
    {
        return $this->hasMany(InventoryHistory::class, 'user_id', 'user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'user_id', 'user_id');
    }
}
