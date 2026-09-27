<?php

declare(strict_types=1);

namespace App\Events\Users;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ProfileUpdated
{
    use Dispatchable;

    /**
     * Someone changed their name or email. Recorded in their personal security log.
     *
     * @param  User  $user  The person, already carrying the change.
     */
    public function __construct(
        public User $user,
    ) {}
}
