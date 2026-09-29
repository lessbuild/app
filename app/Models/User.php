<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $current_account_id
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property bool $getting_started_emails whether they get the welcome and the one setup reminder
 * @property bool $weekly_report_emails whether they get the Monday report on their accounts' projects
 * @property \Illuminate\Support\Carbon|null $onboarding_nudged_at when the setup reminder was sent
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property bool $is_platform_admin operates the platform: opens /admin (granted with `platform:admin`)
 * @property \Illuminate\Support\Carbon|null $platform_admin_granted_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasApiTokens<ApiToken> */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * Hashes `password` when it's set.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'getting_started_emails' => 'boolean',
            'weekly_report_emails' => 'boolean',
            'onboarding_nudged_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'platform_admin_granted_at' => 'datetime',
        ];
    }

    /**
     * Determine whether the person has a second factor: a confirmed authenticator app or a passkey.
     *
     * @return bool
     */
    public function hasSecondFactor(): bool
    {
        return ($this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null) || $this->passkeys()->exists();
    }

    /**
     * Get the person's memberships.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get the accounts they belong to.
     *
     * @return BelongsToMany<Account, $this>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'memberships')->withTimestamps();
    }

    /**
     * Get the account they're working in (`current_account_id`).
     *
     * @return BelongsTo<Account, $this>
     */
    public function currentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'current_account_id');
    }

    /**
     * Get the provider accounts connected to the user.
     *
     * @return HasMany<SocialIdentity, $this>
     */
    public function socialIdentities(): HasMany
    {
        return $this->hasMany(SocialIdentity::class);
    }

    /**
     * Get the user's membership in an account, or null.
     *
     * @param  Account  $account
     * @return Membership|null
     */
    public function membershipIn(Account $account): ?Membership
    {
        return $this->memberships()->whereBelongsTo($account)->first();
    }
}
