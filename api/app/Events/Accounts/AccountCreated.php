<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class AccountCreated
{
    use Dispatchable;

    /**
     * Create a new AccountCreated instance.
     *
     * An account was created, either at registration or by someone adding another account. Recorded in the account's
     * audit log.
     *
     * @param  Account  $account  The new account.
     * @param  User  $owner  The person who created it and became its first owner.
     */
    public function __construct(
        public Account $account,
        public User $owner,
    ) {}
}
