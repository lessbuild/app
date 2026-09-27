<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after the account row (and everything that cascades from it) is gone. */
final readonly class AccountDeleted
{
    use Dispatchable;

    /**
     * An account and everything in it was deleted. The account is gone by the time this fires, so it carries the ID and
     * name instead.
     *
     * @param  string  $accountId  The deleted account's ID.
     * @param  string  $name  Its name, for the record.
     * @param  User  $actor  Who deleted it.
     */
    public function __construct(
        public string $accountId,
        public string $name,
        public User $actor,
    ) {}
}
