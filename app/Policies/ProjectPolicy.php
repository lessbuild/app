<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Require viewing permission in the project's currently selected organization.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Project  $project  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function view(User $user, Project $project): bool
    {
        return (int) $project->organization_id === (int) $user->current_organization_id
            && $project->organization->permits($user, 'view');
    }

    /**
     * Require deployment permission in the project's currently selected organization.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Project  $project  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function update(User $user, Project $project): bool
    {
        return (int) $project->organization_id === (int) $user->current_organization_id
            && $project->organization->permits($user, 'deploy');
    }

    /**
     * Require management permission in the project's currently selected organization.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Project  $project  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function delete(User $user, Project $project): bool
    {
        return (int) $project->organization_id === (int) $user->current_organization_id
            && $project->organization->permits($user, 'manage');
    }
}
