<?php

declare(strict_types=1);

namespace App\Events\Users;

use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after the user row is gone; listeners clean up anything not removed by foreign keys. */
final readonly class UserDeleted
{
    use Dispatchable;

    /**
     * Create a new UserDeleted instance.
     *
     * A person deleted their user and it's gone. Fired after the transaction commits, for anything that must react
     * outside it; the user no longer exists, so only its ID and email are carried.
     *
     * @param  string  $userId  The deleted user's ID.
     * @param  string  $email  The email they used.
     */
    public function __construct(
        public string $userId,
        public string $email,
    ) {}
}
