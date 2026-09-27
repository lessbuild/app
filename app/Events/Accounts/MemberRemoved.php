<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MemberRemoved
{
    use Dispatchable;

    /**
     * A member left or was removed from the account. The audit log records it, the member is notified (unless they left), and incidents
     * assigned to them are unassigned.
     *
     * @param  Account  $account  The account they left.
     * @param  User  $member  The person who is no longer a member.
     * @param  AccountRole  $role  The role they had.
     * @param  User  $actor  Who removed them; the member themselves when they left.
     */
    public function __construct(
        public Account $account,
        public User $member,
        public AccountRole $role,
        public User $actor,
    ) {}
}
