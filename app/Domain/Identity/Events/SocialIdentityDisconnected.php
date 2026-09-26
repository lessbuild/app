<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class SocialIdentityDisconnected
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public SocialProvider $provider,
    ) {}
}
