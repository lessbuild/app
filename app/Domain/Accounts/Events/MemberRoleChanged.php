<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Events;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Membership;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MemberRoleChanged
{
    use Dispatchable;

    public function __construct(
        public Membership $membership,
        public AccountRole $from,
        public AccountRole $to,
        public User $actor,
    ) {}
}
