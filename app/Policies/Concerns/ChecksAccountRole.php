<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;

/** Answers "may this person do X in that account?" from their membership's role and service access. */
trait ChecksAccountRole
{
    /**
     * Whether the person is a member of the account whose role grants the permission and, when a service is named,
     * whose membership isn't limited to other services. People outside the account are always refused.
     */
    private function allows(User $user, string $accountId, AccountPermission $permission, ?string $service = null): bool
    {
        $membership = Membership::query()->where('account_id', $accountId)->where('user_id', $user->id)->first();

        return $membership !== null && $membership->role->allows($permission) && ($service === null || $membership->canUseService($service));
    }

    /**
     * The account an ability is checked in. Create abilities receive the account or, from project pages, the project
     * (account-wide records such as servers are listed there too); other abilities pass the record's account ID.
     */
    private function accountIdOf(Account|Project|string $scope): string
    {
        return match (true) {
            $scope instanceof Project => $scope->account_id,
            $scope instanceof Account => $scope->id,
            default => $scope,
        };
    }
}
