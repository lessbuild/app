<?php

namespace App\Policies;

use App\Models\Build;
use App\Models\User;

class BuildPolicy
{
    /**
     * Allow repository owners or viewers in the currently selected organization to inspect a build.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Build  $build  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function view(User $user, Build $build): bool
    {
        $repository = $build->repository;

        return $repository?->organization
            ? (int) $repository->organization_id === (int) $user->current_organization_id
                && $repository->organization->permits($user, 'view')
            : (int) $repository?->user_id === (int) $user->id;
    }

    /**
     * Allow a visible build to be cancelled by its owner or an organization deployer.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Build  $build  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function cancel(User $user, Build $build): bool
    {
        return $this->view($user, $build)
            && ($build->repository?->organization?->permits($user, 'deploy') ?? true);
    }

    /**
     * Apply the same ownership and deployment permission checks used for cancellation.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Build  $build  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function redeploy(User $user, Build $build): bool
    {
        return $this->cancel($user, $build);
    }

    /**
     * Require a visible build and repository-organization management permission for approval.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Build  $build  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function approve(User $user, Build $build): bool
    {
        return $this->view($user, $build)
            && ($build->repository?->organization?->permits($user, 'manage') ?? false);
    }

    /**
     * Require the same organization management permission used for build approval.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Build  $build  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function rollback(User $user, Build $build): bool
    {
        return $this->approve($user, $build);
    }

    /**
     * Allow owners and organization deployers who can access the build to update its note.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Build  $build  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function updateNote(User $user, Build $build): bool
    {
        return $this->cancel($user, $build);
    }
}
