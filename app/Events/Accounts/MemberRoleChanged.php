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

    public function __construct(
        public Membership $membership,
        public AccountRole $from,
        public AccountRole $to,
        public User $actor,
    ) {}
}
