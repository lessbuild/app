<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MemberInvited
{
    use Dispatchable;

    /**
     * Create a new MemberInvited instance.
     *
     * Someone was invited to join the account. Recorded in the audit log; the invitation email is sent by the action.
     *
     * @param  AccountInvitation  $invitation  The new invitation.
     * @param  User  $actor  Who sent it.
     */
    public function __construct(
        public AccountInvitation $invitation,
        public User $actor,
    ) {}
}
