<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class InvitationRevoked
{
    use Dispatchable;

    /**
     * A pending invitation was withdrawn before it was accepted. Recorded in the audit log.
     *
     * @param  AccountInvitation  $invitation  The revoked invitation.
     * @param  User  $actor  Who revoked it.
     */
    public function __construct(
        public AccountInvitation $invitation,
        public User $actor,
    ) {}
}
