<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountRole;
use App\Services\Billing\Entitlements;
use Illuminate\Auth\Access\Response;

/** Anyone who can use Infrastructure sees load balancers; owners and admins manage them. Adding one needs high availability on the Deploy plan. */
final class LoadBalancerPolicy
{
    use ChecksAccountRole;

    /**
     * Creating a load balancer: the account's settings managers, on a Deploy plan with high availability. The denial
     * says which plan is needed.
     *
     * @param  User  $user
     * @param  Account|Project  $scope
     * @return Response
     */
    public function create(User $user, Account|Project $scope): Response
    {
        $account = $scope instanceof Project ? $scope->account : $scope;
        if (! $this->allows($user, $account->id, AccountPermission::ManageSettings)) {
            return Response::deny();
        }

        return app(Entitlements::class)->for($account)->has('deploy.high_availability')
            ? Response::allow()
            : Response::deny(__('Load balancers come with the Business Deploy plan and above.'));
    }

    /**
     * Changing and removing the account's load balancers (works on any plan).
     *
     * @param  User  $user
     * @param  Project  $project
     * @return bool
     */
    public function manageAny(User $user, Project $project): bool
    {
        return $this->allows($user, $project->account_id, AccountPermission::ManageSettings);
    }

    /**
     * Changing one of the account's load balancers: the account's settings managers, on any plan.
     *
     * @param  User  $user
     * @param  LoadBalancer  $balancer
     * @return bool
     */
    public function update(User $user, LoadBalancer $balancer): bool
    {
        return $this->allows($user, $balancer->account_id, AccountPermission::ManageSettings);
    }

    /**
     * Removing a load balancer, allowed to the same people as update.
     *
     * @param  User  $user
     * @param  LoadBalancer  $balancer
     * @return bool
     */
    public function delete(User $user, LoadBalancer $balancer): bool
    {
        return $this->update($user, $balancer);
    }
}
