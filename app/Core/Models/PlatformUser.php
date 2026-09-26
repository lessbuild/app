<?php

namespace App\Core\Models;

use App\Core\Notifications\PlatformVerifyEmail;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Canonical account record stored in Core.
 *
 * This model is available through Core's platform guard. Product sessions
 * continue using their legacy principal until the host context bridges are
 * ready to preserve each module's existing policies and account workflows.
 *
 * @property string $id
 * @property string|null $email_normalized
 */
class PlatformUser extends Authenticatable implements MustVerifyEmailContract, PasskeyUser
{
    use HasUlids;
    use MustVerifyEmail;
    use Notifiable;
    use PasskeyAuthenticatable;
    use TwoFactorAuthenticatable;

    protected $connection = 'core';

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'email',
        'email_normalized',
        'password',
        'password_set_at',
        'auth_type',
        'email_verified_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'preferences',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_set_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'preferences' => 'array',
            'is_platform_admin' => 'boolean',
            'platform_admin_granted_at' => 'datetime',
        ];
    }

    public function isPlatformAdmin(): bool
    {
        return $this->status === 'active' && (bool) $this->is_platform_admin;
    }

    /** A second factor is an enrolled authenticator app or at least one passkey. */
    public function hasSecondFactor(): bool
    {
        return $this->twoFactorEnabled() || $this->passkeys()->exists();
    }

    /** @return HasMany<WorkspaceMembership, $this> */
    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class, 'user_id');
    }

    /** @return HasMany<UserIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class, 'user_id');
    }

    /** @return HasMany<PlatformAuthSession, $this> */
    public function platformAuthSessions(): HasMany
    {
        return $this->hasMany(PlatformAuthSession::class, 'user_id');
    }

    public function twoFactorEnabled(): bool
    {
        return filled($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }

    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new PlatformVerifyEmail);
    }
}
