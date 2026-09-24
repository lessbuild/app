<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectProductLink;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectResource;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

final class DeployerProjectLink implements ProjectProductLink
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProductAuthentication $authentication,
        private readonly ProductWorkspaceAccess $workspaceAccess,
    ) {}

    public function resolve(PlatformUser $user, CoreProject $project): ?string
    {
        if (! Route::has('projects.show')) {
            return null;
        }

        try {
            $legacyProject = $this->projectFor($user, $project);
        } catch (LostConnectionException|QueryException) {
            return null;
        }

        return $legacyProject !== null ? $this->urlFor($legacyProject) : null;
    }

    public function projectFor(PlatformUser $user, CoreProject $project): ?Project
    {
        $resource = ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('product', 'deployer')
            ->where('resource_type', 'project')
            ->where('status', 'active')
            ->first();

        if ($resource === null) {
            return null;
        }

        $legacyProject = Project::query()->with('organization')->find($resource->resource_id);

        if ($legacyProject === null || $legacyProject->organization === null) {
            return null;
        }

        $sourceUserIds = $this->identities->sourceIdsFor($user, 'deployer');
        if ($this->authentication->usesCoreAuthority('deployer') && count($sourceUserIds) !== 1) {
            return null;
        }

        foreach ($sourceUserIds as $legacyUserId) {
            $legacyUser = User::query()->find($legacyUserId);

            if ($legacyUser === null || ! $legacyProject->organization->permits($legacyUser, 'view')) {
                continue;
            }

            if (! $this->authentication->usesCoreAuthority('deployer')) {
                if ((int) $legacyUser->current_organization_id === (int) $legacyProject->organization_id) {
                    return $legacyProject;
                }

                continue;
            }

            if ($this->workspaceAccess->allows($user, 'deployer', 'organization', $legacyProject->organization_id)) {
                return $legacyProject;
            }
        }

        return null;
    }

    public function urlFor(Project $project, ?string $fragment = null): ?string
    {
        if (! Route::has('projects.show')) {
            return null;
        }

        $parameters = ['project' => $project->getKey()];
        if ($this->authentication->usesCoreAuthority('deployer')) {
            $parameters['organization_id'] = $project->organization_id;
        }

        $url = route('projects.show', $parameters);

        return $fragment === null ? $url : $url.'#'.$fragment;
    }
}
