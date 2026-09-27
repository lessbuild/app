<?php

declare(strict_types=1);

namespace App\Events\Users;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched inside the deletion transaction, just before the user row goes, for data keyed to the user. */
final readonly class UserDeleting
{
    use Dispatchable;

    public function __construct(
        public User $user,
    ) {}
}
