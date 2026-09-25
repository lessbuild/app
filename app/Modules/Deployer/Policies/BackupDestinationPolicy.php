<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\BackupDestination;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;

class BackupDestinationPolicy
{
    /**
     * Allow a manager in the selected workspace to create an encrypted backup destination.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->permits($user, 'manage') ?? false;
    }

    /**
     * Allow only a manager in the destination's selected workspace to remove it.
     */
    public function delete(User $user, BackupDestination $destination): bool
    {
        return (int) $destination->organization_id === (int) $user->current_organization_id
            && ($destination->organization?->permits($user, 'manage') ?? false)
            && app(DeployerProjectAccess::class)->canChangeBackupDestination($user, $destination);
    }

    /** Allow a manager in the selected workspace to verify a destination. */
    public function test(User $user, BackupDestination $destination): bool
    {
        return (int) $destination->organization_id === (int) $user->current_organization_id
            && ($destination->organization?->permits($user, 'manage') ?? false)
            && app(DeployerProjectAccess::class)->canChangeBackupDestination($user, $destination);
    }

    /** Allow a manager in the selected workspace to rotate credentials or connection details. */
    public function update(User $user, BackupDestination $destination): bool
    {
        return (int) $destination->organization_id === (int) $user->current_organization_id
            && ($destination->organization?->permits($user, 'manage') ?? false)
            && app(DeployerProjectAccess::class)->canChangeBackupDestination($user, $destination);
    }
}
