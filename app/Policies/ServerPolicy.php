<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountRole;
use App\Services\Billing\Entitlements;
use Illuminate\Auth\Access\Response;

/**
 * Servers belong to the account. Anyone who can use Infrastructure sees them, reads their logs and runs diagnostics;
 * creating, changing, deleting and running root commands needs account settings access (owners and admins).
 */
final class ServerPolicy
{
    use ChecksAccountRole;

    /**
     * Determine whether the user can see a server: account members who may view projects and use Infrastructure.
     *
     * @param  User  $user
     * @param  Server  $server
     * @return bool
     */
    public function view(User $user, Server $server): bool
    {
        return $this->allows($user, $server->account_id, AccountPermission::ViewProjects, 'infrastructure');
    }

    /**
     * Determine whether the user can create or import a server: people who manage the account's settings.
     *
     * @param  User  $user
     * @param  Account|Project  $scope
     * @return bool
     */
    public function create(User $user, Account|Project $scope): bool
    {
        return $this->allows($user, $this->accountIdOf($scope), AccountPermission::ManageSettings);
    }

    /**
     * Determine whether the user can set the account's infrastructure budget: owners and admins, with cost controls on
     * the Deploy plan.
     *
     * @param  User  $user
     * @param  Account|Project  $scope
     * @return Response
     */
    public function manageCosts(User $user, Account|Project $scope): Response
    {
        $account = $scope instanceof Project ? $scope->account : $scope;
        if (! $this->allows($user, $account->id, AccountPermission::ManageSettings)) {
            return Response::deny();
        }

        return app(Entitlements::class)->for($account)->has('deploy.cost_controls')
            ? Response::allow()
            : Response::deny(__('Budgets come with the Pro Deploy plan and above.'));
    }

    /**
     * Determine whether the user can change a server's settings, firewall and services: people who manage the
     * account's settings.
     *
     * @param  User  $user
     * @param  Server  $server
     * @return bool
     */
    public function update(User $user, Server $server): bool
    {
        return $this->allows($user, $server->account_id, AccountPermission::ManageSettings);
    }

    /**
     * Determine whether the user can delete a server, which the same people as update can.
     *
     * @param  User  $user
     * @param  Server  $server
     * @return bool
     */
    public function delete(User $user, Server $server): bool
    {
        return $this->update($user, $server);
    }

    /**
     * Determine whether the user can run commands and scripts over SSH, which the same people as update can, since
     * that is root access.
     *
     * @param  User  $user
     * @param  Server  $server
     * @return bool
     */
    public function runCommands(User $user, Server $server): bool
    {
        return $this->update($user, $server);
    }

    /**
     * Determine whether the user can open a root shell: the same people who may run commands, on a server with a
     * pinned host key.
     *
     * @param  User  $user
     * @param  Server  $server
     * @return bool
     */
    public function openTerminal(User $user, Server $server): bool
    {
        return $this->runCommands($user, $server);
    }
}
