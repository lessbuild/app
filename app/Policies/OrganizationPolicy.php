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

    /**
     * Allow a manager to change or remove members from the currently selected workspace.
     */
    public function manageMembers(User $user, Organization $organization): bool
    {
        return (int) $organization->id === (int) $user->current_organization_id
            && $organization->permits($user, 'manage');
    }

    /**
     * Allow an account to switch to any workspace in which it has view access.
     */
    public function switch(User $user, Organization $organization): bool
    {
        return $organization->permits($user, 'view');
    }
}
