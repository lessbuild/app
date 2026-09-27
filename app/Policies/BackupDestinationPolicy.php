<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\BackupDestination;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountRole;

/** Backup destinations hold storage credentials, so only owners and admins add, change, check or delete them. */
final class BackupDestinationPolicy
{
    use ChecksAccountRole;

    public function create(User $user, Account|Project $scope): bool
    {
        return $this->allows($user, $scope instanceof Project ? $scope->account_id : $scope->id, AccountPermission::ManageSettings);
    }

    public function update(User $user, BackupDestination $destination): bool
    {
        return $this->allows($user, $destination->account_id, AccountPermission::ManageSettings);
    }

    public function delete(User $user, BackupDestination $destination): bool
    {
        return $this->update($user, $destination);
    }
}
