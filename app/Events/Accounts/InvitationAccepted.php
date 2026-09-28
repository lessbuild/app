<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\AccountInvitation;
use App\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class InvitationAccepted
{
    use Dispatchable;

    /**
     * Create a new InvitationAccepted instance.
     *
     * Someone accepted an invitation and joined the account. Recorded in the audit log, and whoever sent the
     * invitation is notified.
     *
     * @param  AccountInvitation  $invitation  The invitation they accepted.
     * @param  Membership  $membership  The membership it created.
     */
    public function __construct(
        public AccountInvitation $invitation,
        public Membership $membership,
    ) {}
}
