<?php

namespace App\Policies;

use App\Models\Repository;
use App\Models\User;

class RepositoryPolicy
{
    /**
     * Allow the repository owner or a viewer in its currently selected organization.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Repository  $repository  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function view(User $user, Repository $repository): bool
    {
        return $repository->organization
            ? (int) $repository->organization_id === (int) $user->current_organization_id
                && $repository->organization->permits($user, 'view')
            : (int) $repository->user_id === (int) $user->id;
    }

    /**
     * Allow the repository owner or an organization deployer who can view it.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Repository  $repository  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function update(User $user, Repository $repository): bool
    {
        return $this->view($user, $repository)
            && ($repository->organization?->permits($user, 'deploy') ?? true);
    }

    /**
     * Allow the repository owner or an organization manager who can view it.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Repository  $repository  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function delete(User $user, Repository $repository): bool
    {
        return $this->view($user, $repository)
            && ($repository->organization?->permits($user, 'manage') ?? true);
    }

    /**
     * Apply the same ownership and organization deployment checks used for repository updates.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Repository  $repository  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function deploy(User $user, Repository $repository): bool
    {
        return $this->update($user, $repository);
    }
}
