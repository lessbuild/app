<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Events;

use App\Domain\Accounts\Models\Membership;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MemberServiceAccessChanged
{
    use Dispatchable;

    public function __construct(
        public Membership $membership,
        public User $actor,
    ) {}
}
