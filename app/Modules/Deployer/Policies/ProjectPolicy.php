<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;

class ProjectPolicy
{
    /**
     * Require deployment permission in the user's currently selected organization.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->permits($user, 'deploy') ?? false;
    }

    /**
     * Require deployment permission, with management permission for protected environment features.
     *
     * @param  array<string, mixed>  $attributes  Validated environment attributes.
     */
    public function createEnvironment(User $user, Project $project, array $attributes = []): bool
    {
        if (! app(DeployerProjectAccess::class)->project($user, $project)
            || (int) $project->organization_id !== (int) $user->current_organization_id
            || ! $project->organization->permits($user, 'deploy')) {
            return false;
        }

        return (! ($attributes['is_protected'] ?? false) && ! ($attributes['requires_deployment_approval'] ?? false))
            || $project->organization->permits($user, 'manage');
    }

    /**
     * Require workspace management permission to view configuration reviews and receipts.
     */
    public function viewConfiguration(User $user, Project $project): bool
    {
        return app(DeployerProjectAccess::class)->project($user, $project)
            && (int) $project->organization_id === (int) $user->current_organization_id
            && $project->organization->permits($user, 'manage');
    }

    /**
     * Require workspace management permission to plan, review or apply configuration.
     */
    public function manageConfiguration(User $user, Project $project): bool
    {
        return $this->viewConfiguration($user, $project);
    }

    /**
     * Require viewing permission in the project's currently selected organization.
     *
     * @param  User  $user  Account requesting the ability in its current organization.
     * @param  Project  $project  Resource whose ownership and organization permissions are checked.
     * @return bool Whether the account is authorized; lifecycle eligibility is checked by the action.
     */
    public function view(User $user, Project $project): bool
    {
        return app(DeployerProjectAccess::class)->project($user, $project)
            && (int) $project->organization_id === (int) $user->current_organization_id
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
        return app(DeployerProjectAccess::class)->project($user, $project)
            && (int) $project->organization_id === (int) $user->current_organization_id
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
        return app(DeployerProjectAccess::class)->project($user, $project)
            && (int) $project->organization_id === (int) $user->current_organization_id
            && $project->organization->permits($user, 'manage');
    }
}
