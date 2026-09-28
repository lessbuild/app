<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountRole;
use App\Policies\AccountPolicy;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property float|null $monthly_infrastructure_budget in USD; the Infrastructure costs page compares server costs with it
 */
#[Fillable(['name', 'slug'])]
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
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['monthly_infrastructure_budget' => 'float'];
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
