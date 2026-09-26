<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Policies\AccountPolicy;
use App\Domain\Identity\Models\User;
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
 */
#[Fillable(['name', 'slug'])]
#[UseFactory(AccountFactory::class)]
#[UsePolicy(AccountPolicy::class)]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, HasUlids;

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<AccountInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(AccountInvitation::class);
    }

    public function roleOf(User $user): ?AccountRole
    {
        return $this->memberships()->whereBelongsTo($user)->first()?->role;
    }

    public function ownerCount(): int
    {
        return $this->memberships()->where('role', AccountRole::Owner)->count();
    }
}
