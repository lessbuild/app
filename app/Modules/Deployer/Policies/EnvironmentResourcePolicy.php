<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\EnvironmentResource;
use App\Modules\Deployer\Models\User;

class EnvironmentResourcePolicy
{
    /**
     * Allow a workspace member to inspect a resource in the currently selected workspace.
     */
    public function view(User $user, EnvironmentResource $resource): bool
    {
        $organization = $resource->environment?->project?->organization;

        return $organization !== null
            && (int) $organization->id === (int) $user->current_organization_id
            && $organization->permits($user, 'view');
    }

    /**
     * Allow a workspace manager to change a resource in the currently selected workspace.
     */
    public function manage(User $user, EnvironmentResource $resource): bool
    {
        $organization = $resource->environment?->project?->organization;

        return $organization !== null
            && (int) $organization->id === (int) $user->current_organization_id
            && $organization->permits($user, 'manage');
    }
}
