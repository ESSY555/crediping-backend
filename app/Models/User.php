<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['owner', 'manager'];

    protected $fillable = ['name', 'email', 'phone', 'password'];

    public function sendEmailVerificationNotification(): void
    {
        app(\App\Services\EmailOtpService::class)->send($this);
    }

    // is_super_admin is deliberately not fillable: it is granted from the console only.

    protected $hidden = ['password', 'remember_token', 'email_otp_hash', 'password_otp_hash'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_otp_expires_at' => 'datetime',
            'password_otp_expires_at' => 'datetime',
            'password_otp_sent_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_users')
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function roleIn(int $businessId): ?string
    {
        return $this->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('status', 'active')
            ->first()?->pivot?->role;
    }
}
