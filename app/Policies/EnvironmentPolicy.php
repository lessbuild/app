<?php

namespace App\Policies;

use App\Models\Environment;
use App\Models\User;

class EnvironmentPolicy
{
    /**
     * Require viewing permission in the environment project's currently selected organization.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Environment  $environment  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function view(User $user, Environment $environment): bool
    {
        return (int) $environment->project->organization_id === (int) $user->current_organization_id
            && $environment->project->organization->permits($user, 'view');
    }

    /**
     * Require management permission for a protected environment and deployment permission otherwise.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Environment  $environment  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function update(User $user, Environment $environment): bool
    {
        $ability = $environment->is_protected ? 'manage' : 'deploy';

        return (int) $environment->project->organization_id === (int) $user->current_organization_id
            && $environment->project->organization->permits($user, $ability);
    }

    /**
     * Require management permission in the environment project's currently selected organization.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Environment  $environment  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function delete(User $user, Environment $environment): bool
    {
        return (int) $environment->project->organization_id === (int) $user->current_organization_id
            && $environment->project->organization->permits($user, 'manage');
    }
}
