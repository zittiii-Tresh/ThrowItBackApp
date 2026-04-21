<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Admin user for the SiteArchive panel.
 *
 * Implements:
 *   - MustVerifyEmail — new admins added via the Admins management screen
 *     must click the verification link in their email before they can
 *     access the panel. Laravel handles sending + verifying automatically.
 *   - FilamentUser   — canAccessPanel() is the gate: verified users in,
 *     unverified users bounced back to login.
 */
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Filament panel access gate. Only verified users reach /admin;
     * unverified ones see the "please verify your email" notice page.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasVerifiedEmail();
    }
}
