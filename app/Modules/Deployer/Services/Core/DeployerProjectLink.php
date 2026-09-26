<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectProductLink;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectResource;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\MappedProjectResourceAccess;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Models\Environment;
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
            ->first(['resource_id']);

        return $resource !== null
            ? $this->accessibleLocalProject($user, (string) $resource->resource_id, (string) $project->workspace_id)
            : null;
    }

    public function accessibleEnvironment(
        PlatformUser $user,
        CoreProject $project,
        ProjectResource $resource,
    ): ?Environment {
        if ((string) $resource->project_id !== (string) $project->getKey()
            || $resource->product !== 'deployer'
            || $resource->resource_type !== 'environment'
            || $resource->status !== 'active') {
            return null;
        }

        $mapping = ProjectResource::query()
            ->whereKey($resource->getKey())
            ->where('project_id', $project->getKey())
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('status', 'active')
            ->first(['resource_id']);

        if ($mapping === null) {
            return null;
        }

        $mappedEnvironment = Environment::query()
            ->whereKey($mapping->resource_id)
            ->first(['id', 'project_id']);

        if ($mappedEnvironment === null) {
            return null;
        }

        $projectMapping = ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('product', 'deployer')
            ->where('resource_type', 'project')
            ->where('resource_id', (string) $mappedEnvironment->project_id)
            ->where('status', 'active')
            ->first(['id']);
        $legacyProject = $projectMapping === null
            ? null
            : $this->accessibleLocalProject($user, (string) $mappedEnvironment->project_id, (string) $project->workspace_id);

        if ($legacyProject === null
            || ! app(MappedProjectResourceAccess::class)->allows($user, 'deployer', 'environment', $mappedEnvironment->getKey(), 'organization', $legacyProject->organization_id)) {
            return null;
        }

        return Environment::query()
            ->whereKey($mapping->resource_id)
            ->where('project_id', $legacyProject->getKey())
            ->with([
                'project:id,organization_id',
                'server:id,provider_id',
                'website:id,server_id',
                'website.server:id,provider_id',
                'website.repositories' => fn ($repositories) => $repositories
                    ->select(['id', 'website_id', 'provider_id'])
                    ->orderBy('id')
                    ->limit(50),
            ])
            ->first(['id', 'project_id', 'server_id', 'website_id']);
    }

    private function accessibleLocalProject(PlatformUser $user, string $projectId, string $coreWorkspaceId): ?Project
    {
        $legacyProject = Project::query()->with('organization')->find($projectId);

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

            $mappedWorkspaceId = $this->identities->canonicalIdForSource(
                'deployer',
                'organization',
                (string) $legacyProject->organization_id,
                'workspace',
            );

            if ($mappedWorkspaceId !== $coreWorkspaceId) {
                continue;
            }

            if ($this->workspaceAccess->allows($user, 'deployer', 'organization', $legacyProject->organization_id)
                && app(MappedProjectResourceAccess::class)->allows($user, 'deployer', 'project', $legacyProject->getKey(), 'organization', $legacyProject->organization_id)) {
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
