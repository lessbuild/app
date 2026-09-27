<?php

declare(strict_types=1);

namespace App\Events\Users;

use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after the user row is gone; listeners clean up anything not removed by foreign keys. */
final readonly class UserDeleted
{
    use Dispatchable;

    public function __construct(
        public string $userId,
        public string $email,
    ) {}
}
