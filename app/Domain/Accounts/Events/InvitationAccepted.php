<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Events;

use App\Domain\Accounts\Models\AccountInvitation;
use App\Domain\Accounts\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class InvitationAccepted
{
    use Dispatchable;

    public function __construct(
        public AccountInvitation $invitation,
        public Membership $membership,
    ) {}
}
