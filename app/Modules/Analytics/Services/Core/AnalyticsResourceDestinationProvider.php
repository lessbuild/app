<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class AnalyticsResourceDestinationProvider implements ProjectResourceDestinationProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly AnalyticsWorkspaceAccess $access,
    ) {}

    public function destinations(PlatformUser $user, Collection $resources): array
    {
        $mappingIdsByProductResource = $resources
            ->where('resource_type', 'site')
            ->mapWithKeys(fn (ProjectResource $resource): array => [
                (string) $resource->resource_id => (string) $resource->getKey(),
            ]);
        $sourceUserIds = $this->identities->sourceIdsFor($user, 'analytics');

        $destinations = $resources->mapWithKeys(fn (ProjectResource $resource): array => [
            (string) $resource->getKey() => new ProjectResourceDestination(
                $resource->resource_type === 'site'
                    ? ProjectResourceDestinationState::Unavailable
                    : ProjectResourceDestinationState::Unsupported,
            ),
        ])->all();

        if ($mappingIdsByProductResource->isEmpty()) {
            return $destinations;
        }

        if (! Route::has('analytics.dashboard')) {
            return $destinations;
        }

        if ($sourceUserIds === []) {
            foreach ($resources->where('resource_type', 'site') as $resource) {
                $destinations[(string) $resource->getKey()] = new ProjectResourceDestination(
                    ProjectResourceDestinationState::AccessChanged,
                );
            }

            return $destinations;
        }

        $sites = Site::withTrashed()->whereKey($mappingIdsByProductResource->keys())
            ->with('workspace')
            ->get(['id', 'workspace_id', 'deleted_at'])
            ->keyBy(fn (Site $site): string => (string) $site->getKey());
        $accessibleSiteIds = $this->access->filterSites($user, $sites)
            ->mapWithKeys(fn (Site $site): array => [(string) $site->getKey() => true]);

        foreach ($resources->where('resource_type', 'site') as $resource) {
            $mappingId = (string) $resource->getKey();
            $sourceId = (string) $resource->resource_id;
            $site = $sites->get($sourceId);

            $destinations[$mappingId] = $site === null
                ? new ProjectResourceDestination(ProjectResourceDestinationState::Missing)
                : (! $accessibleSiteIds->has($sourceId)
                    ? new ProjectResourceDestination(ProjectResourceDestinationState::AccessChanged)
                    : ($site->trashed()
                        ? new ProjectResourceDestination(ProjectResourceDestinationState::Stale)
                        : new ProjectResourceDestination(ProjectResourceDestinationState::Available, route('analytics.dashboard', ['site' => $sourceId]))));
        }

        return $destinations;
    }
}
