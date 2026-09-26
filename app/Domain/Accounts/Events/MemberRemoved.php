<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Events;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MemberRemoved
{
    use Dispatchable;

    public function __construct(
        public Account $account,
        public User $member,
        public AccountRole $role,
        public User $actor,
    ) {}
}
