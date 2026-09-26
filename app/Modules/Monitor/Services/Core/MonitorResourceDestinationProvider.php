<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class MonitorResourceDestinationProvider implements ProjectResourceDestinationProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly MonitorProjectAccess $projects,
    ) {}

    public function destinations(PlatformUser $user, Collection $resources): array
    {
        $destinations = $resources->mapWithKeys(fn (ProjectResource $resource): array => [
            (string) $resource->getKey() => new ProjectResourceDestination(
                in_array($resource->resource_type, ['application', 'environment'], true)
                    ? ProjectResourceDestinationState::Unavailable
                    : ProjectResourceDestinationState::Unsupported,
            ),
        ])->all();
        $applicationsBySourceId = $resources
            ->where('resource_type', 'application')
            ->mapWithKeys(fn (ProjectResource $resource): array => [
                (string) $resource->resource_id => (string) $resource->getKey(),
            ]);
        $environmentsBySourceId = $resources
            ->where('resource_type', 'environment')
            ->mapWithKeys(fn (ProjectResource $resource): array => [
                (string) $resource->resource_id => (string) $resource->getKey(),
            ]);
        $routesAvailable = (! $applicationsBySourceId->isEmpty() && Route::has('monitor.applications.show'))
            || (! $environmentsBySourceId->isEmpty() && Route::has('monitor.environments.show'));

        if (! $routesAvailable) {
            return $destinations;
        }

        $sourceUserIds = $this->identities->sourceIdsFor($user, 'monitor');

        if ($sourceUserIds === []) {
            foreach ($resources->whereIn('resource_type', ['application', 'environment']) as $resource) {
                $destinations[(string) $resource->getKey()] = new ProjectResourceDestination(
                    ProjectResourceDestinationState::AccessChanged,
                );
            }

            return $destinations;
        }

        $applications = $applicationsBySourceId->isEmpty()
            ? collect()
            : Application::withTrashed()->whereKey($applicationsBySourceId->keys())->get(['id', 'deleted_at'])->keyBy(fn (Application $application): string => (string) $application->getKey());
        $accessibleApplicationIds = $applicationsBySourceId->isEmpty() || ! Route::has('monitor.applications.show')
            ? collect()
            : Application::withTrashed()
                ->whereKey($applicationsBySourceId->keys())
                ->whereHas('workspace.members', fn ($members) => $members->whereIn('users.id', $sourceUserIds))
                ->get()
                ->filter(fn (Application $application): bool => $this->projects->application($user, $application))
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id);
        $environments = $environmentsBySourceId->isEmpty()
            ? collect()
            : Environment::withTrashed()->whereKey($environmentsBySourceId->keys())->get(['id', 'application_id', 'deleted_at'])->keyBy(fn (Environment $environment): string => (string) $environment->getKey());
        $accessibleEnvironmentIds = $environmentsBySourceId->isEmpty() || ! Route::has('monitor.environments.show')
            ? collect()
            : Environment::withTrashed()
                ->whereKey($environmentsBySourceId->keys())
                ->whereHas('application.workspace.members', fn ($members) => $members->whereIn('users.id', $sourceUserIds))
                ->with('application.workspace')
                ->get()
                ->filter(fn (Environment $environment): bool => $this->projects->environment($user, $environment))
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id);

        foreach ($resources->whereIn('resource_type', ['application', 'environment']) as $resource) {
            $mappingId = (string) $resource->getKey();
            $sourceId = (string) $resource->resource_id;

            if ($resource->resource_type === 'application') {
                $application = $applications->get($sourceId);
                $isAccessible = $accessibleApplicationIds->contains($sourceId);
                $routeAvailable = Route::has('monitor.applications.show');

                if ($application === null) {
                    $destinations[$mappingId] = new ProjectResourceDestination(ProjectResourceDestinationState::Missing);
                } elseif (! $isAccessible) {
                    $destinations[$mappingId] = new ProjectResourceDestination(ProjectResourceDestinationState::AccessChanged);
                } elseif ($application->trashed()) {
                    $destinations[$mappingId] = new ProjectResourceDestination(ProjectResourceDestinationState::Stale);
                } elseif (! $routeAvailable) {
                    $destinations[$mappingId] = new ProjectResourceDestination(ProjectResourceDestinationState::Unavailable);
                } else {
                    $destinations[$mappingId] = new ProjectResourceDestination(
                        ProjectResourceDestinationState::Available,
                        route('monitor.applications.show', $sourceId),
                    );
                }

                continue;
            }

            $environment = $environments->get($sourceId);
            $isAccessible = $accessibleEnvironmentIds->contains($sourceId);
            $routeAvailable = Route::has('monitor.environments.show');
            $applicationId = $environment?->application_id;

            if ($environment === null) {
                $destinations[$mappingId] = new ProjectResourceDestination(ProjectResourceDestinationState::Missing);
            } elseif (! $isAccessible) {
                $destinations[$mappingId] = new ProjectResourceDestination(ProjectResourceDestinationState::AccessChanged);
            } elseif ($environment->trashed()) {
                $destinations[$mappingId] = new ProjectResourceDestination(ProjectResourceDestinationState::Stale);
            } elseif (! $routeAvailable || $applicationId === null) {
                $destinations[$mappingId] = new ProjectResourceDestination(ProjectResourceDestinationState::Unavailable);
            } else {
                $destinations[$mappingId] = new ProjectResourceDestination(
                    ProjectResourceDestinationState::Available,
                    route('monitor.environments.show', [$applicationId, $sourceId]),
                );
            }
        }

        return $destinations;
    }
}
