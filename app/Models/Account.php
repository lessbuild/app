<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountRole;
use App\Policies\AccountPolicy;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account is the unit that owns projects, members and billing (a Cloudflare account).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $referral_code the code in the account's refer-a-friend link
 * @property float|null $monthly_infrastructure_budget in USD; the Infrastructure costs page compares server costs with it
 * @property bool $require_two_factor members must turn on two-factor authentication to use the account
 * @property list<string>|null $allowed_email_domains only people with these email domains can be invited or sign in with SSO
 * @property list<string>|null $allowed_ip_ranges the account can only be used from these IP addresses or CIDR ranges
 * @property int|null $session_idle_minutes sign people out after this long without a request
 * @property string|null $sso_issuer the OIDC identity provider's issuer URL
 * @property string|null $sso_client_id
 * @property string|null $sso_client_secret encrypted
 * @property string $sso_protocol oidc or saml
 * @property string|null $saml_idp_entity_id the SAML identity provider's entity ID
 * @property string|null $saml_idp_sso_url where SAML sign-in requests go (HTTP-Redirect binding)
 * @property string|null $saml_idp_certificate the identity provider's X.509 signing certificate (PEM)
 * @property string|null $scim_token_hash SHA-256 of the SCIM bearer token; null when provisioning is off
 * @property string|null $brand_name the name clients see instead of ours (white label)
 * @property string|null $brand_logo_url an HTTPS image shown on client-facing pages
 * @property string|null $brand_color #rrggbb accent for client-facing pages
 * @property string $scim_default_role the role people added through SCIM get
 * @property bool $sso_enforced members must sign in through the identity provider to use the account
 */
#[Fillable(['name', 'slug'])]
#[Hidden(['sso_client_secret', 'scim_token_hash'])]
#[UseFactory(AccountFactory::class)]
#[UsePolicy(AccountPolicy::class)]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the account's projects.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get who belongs to the account and with which role.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get the people who belong to the account, through their memberships.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')->withPivot('role')->withTimestamps();
    }

    /**
     * Get the invitations sent from the account, pending or not.
     *
     * @return HasMany<AccountInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(AccountInvitation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the security rules as booleans and JSON lists, and encrypts the SSO client secret.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_infrastructure_budget' => 'float', 'require_two_factor' => 'boolean', 'allowed_email_domains' => 'array', 'allowed_ip_ranges' => 'array',
            'session_idle_minutes' => 'integer', 'sso_client_secret' => 'encrypted', 'sso_enforced' => 'boolean',
        ];
    }

    /**
     * Determine whether single sign-on has an issuer, client ID and secret.
     *
     * @return bool
     */
    public function hasSso(): bool
    {
        if ($this->sso_protocol === 'saml') {
            return filled($this->saml_idp_entity_id) && filled($this->saml_idp_sso_url) && filled($this->saml_idp_certificate);
        }

        return filled($this->sso_issuer) && filled($this->sso_client_id) && filled($this->sso_client_secret);
    }

    /**
     * Determine whether an email address's domain is allowed (always, when no domains are set).
     *
     * @param  string  $email
     * @return bool
     */
    public function allowsEmail(string $email): bool
    {
        $domains = $this->allowed_email_domains ?? [];

        return $domains === [] || in_array(mb_strtolower(\Illuminate\Support\Str::afterLast($email, '@')), $domains, true);
    }

    /**
     * Get the person's role in the account, or null when they aren't a member.
     *
     * @param  User  $user
     * @return AccountRole|null
     */
    public function roleOf(User $user): ?AccountRole
    {
        return $this->memberships()->whereBelongsTo($user)->first()?->role;
    }

    /**
     * Count the account's owners, which must never drop to zero.
     *
     * @return int
     */
    public function ownerCount(): int
    {
        return $this->memberships()->where('role', AccountRole::Owner)->count();
    }
}
