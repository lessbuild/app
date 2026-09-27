<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\AccountPermission;
use App\Models\Membership;
use App\Models\User;

/** Answers "may this person do X in that account?" from their membership's role and service access. */
trait ChecksAccountRole
{
    private function allows(User $user, string $accountId, AccountPermission $permission, ?string $service = null): bool
    {
        $membership = Membership::query()->where('account_id', $accountId)->where('user_id', $user->id)->first();

        return $membership !== null && $membership->role->allows($permission) && ($service === null || $membership->canUseService($service));
    }
}
