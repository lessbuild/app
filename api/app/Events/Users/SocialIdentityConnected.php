<?php

declare(strict_types=1);

namespace App\Events\Users;

use App\Enums\SocialProvider;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class SocialIdentityConnected
{
    use Dispatchable;

    /**
     * Create a new SocialIdentityConnected instance.
     *
     * Someone connected a sign-in provider account. Recorded in their personal security log.
     *
     * @param  User  $user  The person.
     * @param  SocialProvider  $provider  The provider they connected.
     */
    public function __construct(
        public User $user,
        public SocialProvider $provider,
    ) {}
}
