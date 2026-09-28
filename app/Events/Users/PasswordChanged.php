<?php

declare(strict_types=1);

namespace App\Events\Users;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class PasswordChanged
{
    use Dispatchable;

    /**
     * Create a new PasswordChanged instance.
     *
     * Someone changed or set their password. Recorded in their personal security log.
     *
     * @param  User  $user  The person.
     */
    public function __construct(
        public User $user,
    ) {}
}
