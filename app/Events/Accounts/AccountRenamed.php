<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class AccountRenamed
{
    use Dispatchable;

    public function __construct(
        public Account $account,
        public string $from,
        public User $actor,
    ) {}
}
