<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class BrowsersSignedOut
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public int $count,
    ) {}
}
