<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Queries;

use App\Domain\Accounts\Data\Departure;
use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Membership;
use App\Domain\Identity\Models\User;

final class DepartureQuery
{
    public function handle(User $user): Departure
    {
        $toDelete = $toLeave = $blockedBy = [];
        foreach ($user->memberships()->with('account')->get() as $membership) {
            $others = Membership::query()->where('account_id', $membership->account_id)->where('user_id', '!=', $user->id);
            if (! $others->exists()) {
                $toDelete[] = $membership->account;
            } elseif ($membership->role === AccountRole::Owner && ! $others->where('role', AccountRole::Owner)->exists()) {
                $blockedBy[] = $membership->account;
            } else {
                $toLeave[] = $membership;
            }
        }

        return new Departure($toDelete, $toLeave, $blockedBy);
    }
}
