<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
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
