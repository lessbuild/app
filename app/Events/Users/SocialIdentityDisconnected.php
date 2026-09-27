<?php

declare(strict_types=1);

namespace App\Events\Users;

use App\Enums\SocialProvider;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class SocialIdentityDisconnected
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public SocialProvider $provider,
    ) {}
}
