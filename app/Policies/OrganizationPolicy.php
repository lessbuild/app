<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Allow a manager to invite members only to the currently selected workspace.
     */
    public function invite(User $user, Organization $organization): bool
    {
        return (int) $organization->id === (int) $user->current_organization_id
            && $organization->permits($user, 'manage');
    }
}
