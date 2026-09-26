<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\MappedProjectResourceAccess;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

final class DeployerResourceDestinationProvider implements ProjectResourceDestinationProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProductAuthentication $authentication,
        private readonly ProductWorkspaceAccess $workspaceAccess,
    ) {}

    public function destinations(PlatformUser $user, Collection $resources): array
    {
        $destinations = $resources->mapWithKeys(fn (ProjectResource $resource): array => [
            (string) $resource->getKey() => new ProjectResourceDestination(
                in_array($resource->resource_type, ['project', 'environment'], true)
                    ? ProjectResourceDestinationState::Unavailable
                    : ProjectResourceDestinationState::Unsupported,
            ),
        ])->all();
        $supportedResources = $resources->whereIn('resource_type', ['project', 'environment']);

        if ($supportedResources->isEmpty()) {
            return $destinations;
        }

        if (! Route::has('projects.show')) {
            return $destinations;
        }

        $projectsBySourceId = $supportedResources
            ->where('resource_type', 'project')
            ->mapWithKeys(fn (ProjectResource $resource): array => [
                (string) $resource->resource_id => (string) $resource->getKey(),
            ]);
        $environmentsBySourceId = $supportedResources
            ->where('resource_type', 'environment')
            ->mapWithKeys(fn (ProjectResource $resource): array => [
                (string) $resource->resource_id => (string) $resource->getKey(),
            ]);
        $sourceUserIds = $this->identities->sourceIdsFor($user, 'deployer');

        if ($sourceUserIds === []) {
            foreach ($supportedResources as $resource) {
                $destinations[(string) $resource->getKey()] = new ProjectResourceDestination(
                    ProjectResourceDestinationState::AccessChanged,
                );
            }

            return $destinations;
        }

        $usesCoreAuthority = $this->authentication->usesCoreAuthority('deployer');
        if ($usesCoreAuthority && count($sourceUserIds) !== 1) {
            foreach ($supportedResources as $resource) {
                $destinations[(string) $resource->getKey()] = new ProjectResourceDestination(
                    ProjectResourceDestinationState::AccessChanged,
                );
            }

            return $destinations;
        }

        $sourceUsersQuery = User::query()->whereKey($sourceUserIds);
        if (! $usesCoreAuthority) {
            $sourceUsersQuery->whereNotNull('current_organization_id');
        }
        $sourceUsers = $sourceUsersQuery
            ->get(['id', 'current_organization_id'])
            ->keyBy(fn (User $sourceUser): string => (string) $sourceUser->getKey());
        $projects = $projectsBySourceId->isEmpty()
            ? collect()
            : Project::query()
                ->whereKey($projectsBySourceId->keys())
                ->with('organization:id,owner_id')
                ->get(['id', 'organization_id'])
                ->keyBy(fn (Project $project): string => (string) $project->getKey());
        $environments = $environmentsBySourceId->isEmpty()
            ? collect()
            : Environment::query()
                ->whereKey($environmentsBySourceId->keys())
                ->with('project.organization:id,owner_id')
                ->get(['id', 'project_id'])
                ->keyBy(fn (Environment $environment): string => (string) $environment->getKey());
        $projectsById = $projects->union($environments->map(fn (Environment $environment): ?Project => $environment->project)->filter()
            ->keyBy(fn (Project $project): string => (string) $project->getKey()));
        $organizationIds = $projectsById->pluck('organization_id')->unique()->values();

        $membershipPairs = $organizationIds->isEmpty() || $sourceUsers->isEmpty()
            ? collect()
            : DB::connection('deployer')
                ->table('organization_user')
                ->whereIn('organization_id', $organizationIds)
                ->whereIn('user_id', $sourceUsers->keys())
                ->get(['organization_id', 'user_id'])
                ->mapWithKeys(fn ($membership): array => [
                    $membership->organization_id.':'.$membership->user_id => true,
                ]);

        foreach ($supportedResources as $resource) {
            $sourceId = (string) $resource->resource_id;
            $sourceEnvironment = $resource->resource_type === 'environment'
                ? $environments->get($sourceId)
                : null;
            $sourceProject = $resource->resource_type === 'project'
                ? $projects->get($sourceId)
                : $sourceEnvironment?->project;
            $mappingId = (string) $resource->getKey();

            if ($sourceProject === null) {
                $destinations[$mappingId] = new ProjectResourceDestination(ProjectResourceDestinationState::Missing);

                continue;
            }

            $canView = $sourceUsers->contains(function (User $sourceUser) use ($membershipPairs, $sourceProject, $usesCoreAuthority, $user): bool {
                if ((! $usesCoreAuthority && (int) $sourceUser->current_organization_id !== (int) $sourceProject->organization_id)
                    || $sourceProject->organization === null) {
                    return false;
                }

                $hasLocalMembership = (int) $sourceProject->organization->owner_id === (int) $sourceUser->getKey()
                    || $membershipPairs->has($sourceProject->organization_id.':'.$sourceUser->getKey());

                return $hasLocalMembership
                    && app(MappedProjectResourceAccess::class)->allows($user, 'deployer', 'project', $sourceProject->getKey(), 'organization', $sourceProject->organization_id)
                    && (! $usesCoreAuthority || $this->workspaceAccess->allows($user, 'deployer', 'organization', $sourceProject->organization_id));
            });

            $destinationParameters = ['project' => $sourceProject->getKey()];
            if ($usesCoreAuthority) {
                $destinationParameters['organization_id'] = $sourceProject->organization_id;
            }

            $canView = $canView && ($sourceEnvironment === null
                || app(MappedProjectResourceAccess::class)->allows($user, 'deployer', 'environment', $sourceEnvironment->getKey(), 'organization', $sourceProject->organization_id));

            $destinations[$mappingId] = $canView
                ? new ProjectResourceDestination(
                    ProjectResourceDestinationState::Available,
                    route('projects.show', $destinationParameters).($sourceEnvironment ? '#environment-'.$sourceEnvironment->getKey() : ''),
                )
                : new ProjectResourceDestination(ProjectResourceDestinationState::AccessChanged);
        }

        return $destinations;
    }
}
