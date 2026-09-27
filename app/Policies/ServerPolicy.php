<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountRole;

/**
 * Servers belong to the account. Anyone who can use Infrastructure sees them, reads their logs and runs diagnostics;
 * creating, changing, deleting and running root commands needs account settings access (owners and admins).
 */
final class ServerPolicy
{
    use ChecksAccountRole;

    public function view(User $user, Server $server): bool
    {
        return $this->allows($user, $server->account_id, AccountPermission::ViewProjects, 'infrastructure');
    }

    public function create(User $user, Account|Project $scope): bool
    {
        return $this->allows($user, $scope instanceof Project ? $scope->account_id : $scope->id, AccountPermission::ManageSettings);
    }

    public function update(User $user, Server $server): bool
    {
        return $this->allows($user, $server->account_id, AccountPermission::ManageSettings);
    }

    public function delete(User $user, Server $server): bool
    {
        return $this->update($user, $server);
    }

    public function runCommands(User $user, Server $server): bool
    {
        return $this->update($user, $server);
    }
}
