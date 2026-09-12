<?php

namespace App\Policies;

use App\Models\OperationalIncident;
use App\Models\User;

class OperationalIncidentPolicy
{
    /**
     * Allow an auditor or operator to export incidents from the selected workspace.
     */
    public function export(User $user): bool
    {
        $organization = $user->currentOrganization;
        if ($organization === null) {
            return false;
        }

        return $organization->permits($user, 'audit') || $organization->permits($user, 'operate');
    }

    /**
     * Allow operations responders to acknowledge an incident in the selected workspace.
     */
    public function acknowledge(User $user, OperationalIncident $incident): bool
    {
        return $this->operates($user, $incident);
    }

    /**
     * Allow operations responders to assign an incident in the selected workspace.
     */
    public function assign(User $user, OperationalIncident $incident): bool
    {
        return $this->operates($user, $incident);
    }

    /**
     * Allow operations responders to append a note to an incident in the selected workspace.
     */
    public function note(User $user, OperationalIncident $incident): bool
    {
        return $this->operates($user, $incident);
    }

    /**
     * Allow operations responders to resolve an incident in the selected workspace.
     */
    public function resolve(User $user, OperationalIncident $incident): bool
    {
        return $this->operates($user, $incident);
    }

    private function operates(User $user, OperationalIncident $incident): bool
    {
        return (int) $incident->organization_id === (int) $user->current_organization_id
            && $incident->organization->permits($user, 'operate');
    }
}
