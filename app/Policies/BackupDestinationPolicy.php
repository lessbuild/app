<?php

namespace App\Policies;

use App\Models\BackupDestination;
use App\Models\User;

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
            && ($destination->organization?->permits($user, 'manage') ?? false);
    }
}
