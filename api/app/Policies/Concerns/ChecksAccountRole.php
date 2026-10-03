<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;

/** Answers "may this person do X in that account?" from their membership's role and service access. */
trait ChecksAccountRole
{
    /**
     * Determine whether the person is a member of the account whose role grants the permission and, when a service is
     * named, whose membership isn't limited to other services. People outside the account are always refused.
     *
     * @param  User  $user
     * @param  string  $accountId
     * @param  AccountPermission  $permission
     * @param  string|null  $service
     * @return bool
     */
    private function allows(User $user, string $accountId, AccountPermission $permission, ?string $service = null): bool
    {
        $membership = Membership::query()->where('account_id', $accountId)->where('user_id', $user->id)->first();

        return $membership !== null && $membership->role->allows($permission) && ($service === null || $membership->canUseService($service));
    }

    /**
     * Determine whether the person may do something in a project: their role grants the permission, their membership
     * isn't limited to other services, and they can see the project.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AccountPermission  $permission
     * @param  string|null  $service
     * @return bool
     */
    private function allowsInProject(User $user, Project $project, AccountPermission $permission, ?string $service = null): bool
    {
        $membership = Membership::query()->where('account_id', $project->account_id)->where('user_id', $user->id)->first();

        return $membership !== null && $membership->role->allows($permission) && ($service === null || $membership->canUseService($service))
            && $membership->canSeeProject($project->id);
    }

    /**
     * Determine whether the person may deploy to or change an environment that's protected (unprotected ones need no
     * more than their role).
     *
     * @param  User  $user
     * @param  Environment|null  $environment
     * @return bool
     */
    private function mayTouchEnvironment(User $user, ?Environment $environment): bool
    {
        if ($environment === null || ! $environment->protected) {
            return true;
        }

        return (bool) Membership::query()->where('account_id', $environment->project->account_id)->where('user_id', $user->id)->first()?->canDeployProtected();
    }

    /**
     * Resolve the account an ability is checked in. Create abilities receive the account or, from project pages, the
     * project (account-wide records such as servers are listed there too); other abilities pass the record's account
     * ID.
     *
     * @param  Account|Project|string  $scope
     * @return string
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
