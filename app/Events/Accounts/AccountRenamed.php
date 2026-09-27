<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class AccountRenamed
{
    use Dispatchable;

    /**
     * An account's name changed. Recorded in the audit log.
     *
     * @param  Account  $account  The account, already carrying its new name.
     * @param  string  $from  The name it had before.
     * @param  User  $actor  Who renamed it.
     */
    public function __construct(
        public Account $account,
        public string $from,
        public User $actor,
    ) {}
}
