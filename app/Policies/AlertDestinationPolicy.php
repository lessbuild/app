<?php

namespace App\Policies;

use App\Models\AlertDestination;
use App\Models\User;

class AlertDestinationPolicy
{
    /**
     * Allow a manager in the user's current workspace to create an alert destination.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->permits($user, 'manage') ?? false;
    }

    /**
     * Allow a manager to test only a destination belonging to the selected workspace.
     */
    public function test(User $user, AlertDestination $destination): bool
    {
        return $this->manages($user, $destination);
    }

    /**
     * Allow a manager to delete only a destination belonging to the selected workspace.
     */
    public function delete(User $user, AlertDestination $destination): bool
    {
        return $this->manages($user, $destination);
    }

    private function manages(User $user, AlertDestination $destination): bool
    {
        return (int) $destination->organization_id === (int) $user->current_organization_id
            && $destination->organization->permits($user, 'manage');
    }
}
