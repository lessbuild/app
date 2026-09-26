<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\AccountPermission;
use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Identity\Models\User;
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
        return ['role' => AccountRole::class];
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
