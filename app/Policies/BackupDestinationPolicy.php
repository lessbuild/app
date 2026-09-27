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

    /**
     * Adding a storage destination for backups: people who manage the account's settings.
     */
    public function create(User $user, Account|Project $scope): bool
    {
        return $this->allows($user, $this->accountIdOf($scope), AccountPermission::ManageSettings);
    }

    /**
     * Changing a destination's credentials or settings: the same people.
     */
    public function update(User $user, BackupDestination $destination): bool
    {
        return $this->allows($user, $destination->account_id, AccountPermission::ManageSettings);
    }

    /**
     * Removing a destination, allowed to the same people as update.
     */
    public function delete(User $user, BackupDestination $destination): bool
    {
        return $this->update($user, $destination);
    }
}
