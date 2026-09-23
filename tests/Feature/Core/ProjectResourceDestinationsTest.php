<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\ProjectResourceDestinationRegistry;
use App\Core\Services\ProjectResourceDestinations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class ProjectResourceDestinationsTest extends TestCase
{
    public function test_destinations_are_resolved_by_product_and_unknown_products_are_omitted(): void
    {
        $registry = new ProjectResourceDestinationRegistry;
        $registry->register('monitor', new class implements ProjectResourceDestinationProvider
        {
            public function destinations(PlatformUser $user, Collection $resources): array
            {
                return $resources->mapWithKeys(fn (ProjectResource $resource): array => [
                    (string) $resource->getKey() => '/monitor/applications/'.$resource->resource_id,
                ])->all();
            }
        });

        $resources = collect([
            (new ProjectResource)->forceFill([
                'id' => 'map_01',
                'product' => 'monitor',
                'resource_type' => 'application',
                'resource_id' => '42',
            ]),
            (new ProjectResource)->forceFill([
                'id' => 'map_02',
                'product' => 'unknown',
                'resource_type' => 'service',
                'resource_id' => '99',
            ]),
        ]);

        $destinations = (new ProjectResourceDestinations($registry))->forResources(new PlatformUser, $resources);

        $this->assertSame(['map_01' => '/monitor/applications/42'], $destinations);
    }

    public function test_unavailable_product_does_not_break_other_resource_destinations(): void
    {
        $registry = new ProjectResourceDestinationRegistry;
        $registry->register('monitor', new class implements ProjectResourceDestinationProvider
        {
            public function destinations(PlatformUser $user, Collection $resources): array
            {
                throw new QueryException('monitor', 'select', [], new \RuntimeException('offline'));
            }
        });
        $registry->register('analytics', new class implements ProjectResourceDestinationProvider
        {
            public function destinations(PlatformUser $user, Collection $resources): array
            {
                return ['analytics_map' => '/analytics/sites/7'];
            }
        });

        $resources = collect([
            (new ProjectResource)->forceFill(['id' => 'monitor_map', 'product' => 'monitor']),
            (new ProjectResource)->forceFill(['id' => 'analytics_map', 'product' => 'analytics']),
        ]);

        $this->assertSame(
            ['analytics_map' => '/analytics/sites/7'],
            (new ProjectResourceDestinations($registry))->forResources(new PlatformUser, $resources),
        );
    }
}
