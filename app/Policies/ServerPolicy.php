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
     * Seeing a server: account members who may view projects and use Infrastructure.
     */
    public function view(User $user, Server $server): bool
    {
        return $this->allows($user, $server->account_id, AccountPermission::ViewProjects, 'infrastructure');
    }

    /**
     * Creating or importing a server: people who manage the account's settings.
     */
    public function create(User $user, Account|Project $scope): bool
    {
        return $this->allows($user, $this->accountIdOf($scope), AccountPermission::ManageSettings);
    }

    /** Setting the account's infrastructure budget: owners and admins, with cost controls on the Deploy plan. */
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
     * Changing a server's settings, firewall and services: people who manage the account's settings.
     */
    public function update(User $user, Server $server): bool
    {
        return $this->allows($user, $server->account_id, AccountPermission::ManageSettings);
    }

    /**
     * Deleting a server, allowed to the same people as update.
     */
    public function delete(User $user, Server $server): bool
    {
        return $this->update($user, $server);
    }

    /**
     * Running commands and scripts over SSH, allowed to the same people as update, since that is root access.
     */
    public function runCommands(User $user, Server $server): bool
    {
        return $this->update($user, $server);
    }

    /** A root shell: the same people who may run commands, on a server with a pinned host key. */
    public function openTerminal(User $user, Server $server): bool
    {
        return $this->runCommands($user, $server);
    }
}
