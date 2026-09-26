<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Events;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after the account row (and everything that cascades from it) is gone. */
final readonly class AccountDeleted
{
    use Dispatchable;

    public function __construct(
        public string $accountId,
        public string $name,
        public User $actor,
    ) {}
}
