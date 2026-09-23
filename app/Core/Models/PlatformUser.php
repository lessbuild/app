<?php

namespace App\Core\Models;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Canonical account record stored in Core.
 *
 * This model is intentionally not the configured auth provider yet: existing
 * product accounts must be reconciled before Core becomes authoritative.
 *
 * @property string $id
 * @property string|null $email_normalized
 */
class PlatformUser extends Authenticatable implements MustVerifyEmailContract
{
    use HasUlids;
    use MustVerifyEmail;
    use Notifiable;

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
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'preferences' => 'array',
        ];
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

    /** @return HasMany<Passkey, $this> */
    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class, 'user_id');
    }
}
