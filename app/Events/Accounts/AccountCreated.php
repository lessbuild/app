<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class AccountCreated
{
    use Dispatchable;

    public function __construct(
        public Account $account,
        public User $owner,
    ) {}
}
