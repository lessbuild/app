<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Events;

use App\Domain\Accounts\Models\AccountInvitation;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class InvitationRevoked
{
    use Dispatchable;

    public function __construct(
        public AccountInvitation $invitation,
        public User $actor,
    ) {}
}
