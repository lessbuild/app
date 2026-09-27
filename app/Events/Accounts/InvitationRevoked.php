<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class InvitationRevoked
{
    use Dispatchable;

    public function __construct(
        public AccountInvitation $invitation,
        public User $actor,
    ) {}
}
