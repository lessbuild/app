<?php

namespace App\Core\Services;

use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

final class ProjectResourceDestinations
{
    public function __construct(private readonly ProjectResourceDestinationRegistry $providers) {}

    /**
     * @param  Collection<int, ProjectResource>  $resources
     * @return array<string, ProjectResourceDestination>
     */
    public function forResources(PlatformUser $user, Collection $resources): array
    {
        $destinations = [];

        foreach ($resources->groupBy('product') as $product => $productResources) {
            $staleResources = $productResources->reject(fn (ProjectResource $resource): bool => $resource->status === 'active');
            $destinations += $this->withState($staleResources, ProjectResourceDestinationState::Stale);
            $activeResources = $productResources->where('status', 'active');

            if ($activeResources->isEmpty()) {
                continue;
            }

            $provider = $this->providers->get((string) $product);

            if ($provider === null) {
                $destinations += $this->withState($activeResources, ProjectResourceDestinationState::Unavailable);

                continue;
            }

            try {
                $destinations += $provider->destinations($user, $activeResources);
            } catch (LostConnectionException|QueryException) {
                $destinations += $this->withState($activeResources, ProjectResourceDestinationState::Unavailable);
            }
        }

        return $destinations;
    }

    /**
     * @param  Collection<int, ProjectResource>  $resources
     * @return array<string, ProjectResourceDestination>
     */
    private function withState(Collection $resources, ProjectResourceDestinationState $state): array
    {
        return $resources->mapWithKeys(fn (ProjectResource $resource): array => [
            (string) $resource->getKey() => new ProjectResourceDestination($state),
        ])->all();
    }
}
