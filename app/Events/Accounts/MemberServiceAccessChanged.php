<?php

declare(strict_types=1);

namespace App\Events\Accounts;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MemberServiceAccessChanged
{
    use Dispatchable;

    /**
     * The services a member may use changed. The audit log records it, and incidents in services they lost are
     * unassigned from them.
     *
     * @param  Membership  $membership  The membership, already carrying the new service list.
     * @param  User  $actor  Who changed it.
     */
    public function __construct(
        public Membership $membership,
        public User $actor,
    ) {}
}
