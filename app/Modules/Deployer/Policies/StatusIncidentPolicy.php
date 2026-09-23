<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\StatusIncident;
use App\Modules\Deployer\Models\User;

class StatusIncidentPolicy
{
    /**
     * Allow a manager in the selected workspace to create a status update.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->permits($user, 'manage') ?? false;
    }

    /**
     * Allow a manager to update only an incident on a status page in the selected workspace.
     */
    public function update(User $user, StatusIncident $incident): bool
    {
        $page = $incident->statusPage;

        return $page !== null
            && (int) $page->organization_id === (int) $user->current_organization_id
            && $page->organization->permits($user, 'manage');
    }
}
