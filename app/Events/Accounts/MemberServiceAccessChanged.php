<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MemberServiceAccessChanged
{
    use Dispatchable;

    public function __construct(
        public Membership $membership,
        public User $actor,
    ) {}
}
