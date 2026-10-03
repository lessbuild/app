<?php

declare(strict_types=1);

namespace App\Events\Users;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class BrowsersSignedOut
{
    use Dispatchable;

    /**
     * Create a new BrowsersSignedOut instance.
     *
     * Someone signed out their other browsers. Recorded in their personal security log.
     *
     * @param  User  $user  The person.
     * @param  int  $count  How many sessions ended.
     */
    public function __construct(
        public User $user,
        public int $count,
    ) {}
}
