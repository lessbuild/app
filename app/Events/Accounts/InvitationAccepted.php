<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\AccountInvitation;
use App\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class InvitationAccepted
{
    use Dispatchable;

    public function __construct(
        public AccountInvitation $invitation,
        public Membership $membership,
    ) {}
}
