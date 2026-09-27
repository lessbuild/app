<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Role is deliberately not mass-assignable; it changes only through account actions.
 *
 * @property string $id
 * @property string $account_id
 * @property string $user_id
 * @property AccountRole $role
 * @property list<string>|null $service_access null means every service
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Account $account
 * @property-read User $user
 */
class Membership extends Model
{
    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => AccountRole::class, 'service_access' => 'array'];
    }

    /** Owners and admins always reach every service; others may be limited to a list. */
    public function canUseService(string $service): bool
    {
        return in_array($this->role, [AccountRole::Owner, AccountRole::Admin], true)
            || $this->service_access === null
            || in_array($service, $this->service_access, true);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allows(AccountPermission $permission): bool
    {
        return $this->role->allows($permission);
    }
}
