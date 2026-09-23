<?php

namespace App\Core\Services;

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
     * @return array<string, string>
     */
    public function forResources(PlatformUser $user, Collection $resources): array
    {
        $destinations = [];

        foreach ($resources->groupBy('product') as $product => $productResources) {
            $provider = $this->providers->get((string) $product);

            if ($provider === null) {
                continue;
            }

            try {
                $destinations += $provider->destinations($user, $productResources);
            } catch (LostConnectionException|QueryException) {
                // A product outage removes its links without breaking the Core project page.
            }
        }

        return $destinations;
    }
}
