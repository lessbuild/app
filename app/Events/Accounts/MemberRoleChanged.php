<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Enums\AccountRole;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MemberRoleChanged
{
    use Dispatchable;

    /**
     * A member's role changed. The audit log records it, the member is notified (unless they
     * changed it themselves), and incidents they can no longer see
     * are unassigned from them.
     *
     * @param  Membership  $membership  The membership, already carrying the new role.
     * @param  AccountRole  $from  The role they had.
     * @param  AccountRole  $to  The role they have now.
     * @param  User  $actor  Who changed it.
     */
    public function __construct(
        public Membership $membership,
        public AccountRole $from,
        public AccountRole $to,
        public User $actor,
    ) {}
}
