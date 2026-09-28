<?php

declare(strict_types=1);

namespace App\Queries\Accounts;

use App\Data\Accounts\Departure;
use App\Enums\AccountRole;
use App\Models\Membership;
use App\Models\User;

final class DepartureQuery
{
    /**
     * Sort the person's accounts by what deleting them would mean: accounts they're alone in get deleted, accounts
     * where they're the only owner block the deletion until someone else is made owner, and the rest they simply
     * leave.
     *
     * @param  User  $user
     * @return Departure
     */
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
