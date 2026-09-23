<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\ProjectResourceDestinationRegistry;
use App\Core\Services\ProjectResourceDestinations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class ProjectResourceDestinationsTest extends TestCase
{
    public function test_destinations_are_resolved_by_product_and_unknown_products_are_marked_unavailable(): void
    {
        $registry = new ProjectResourceDestinationRegistry;
        $registry->register('monitor', new class implements ProjectResourceDestinationProvider
        {
            public function destinations(PlatformUser $user, Collection $resources): array
            {
                return $resources->mapWithKeys(fn (ProjectResource $resource): array => [
                    (string) $resource->getKey() => new ProjectResourceDestination(
                        ProjectResourceDestinationState::Available,
                        '/monitor/applications/'.$resource->resource_id,
                    ),
                ])->all();
            }
        });

        $resources = collect([
            (new ProjectResource)->forceFill([
                'id' => 'map_01',
                'product' => 'monitor',
                'resource_type' => 'application',
                'resource_id' => '42',
                'status' => 'active',
            ]),
            (new ProjectResource)->forceFill([
                'id' => 'map_02',
                'product' => 'unknown',
                'resource_type' => 'service',
                'resource_id' => '99',
                'status' => 'active',
            ]),
        ]);

        $destinations = (new ProjectResourceDestinations($registry))->forResources(new PlatformUser, $resources);

        $this->assertEquals(
            new ProjectResourceDestination(ProjectResourceDestinationState::Available, '/monitor/applications/42'),
            $destinations['map_01'],
        );
        $this->assertEquals(
            ProjectResourceDestinationState::Unavailable,
            $destinations['map_02']->state,
        );
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
                return ['analytics_map' => new ProjectResourceDestination(
                    ProjectResourceDestinationState::Available,
                    '/analytics/sites/7',
                )];
            }
        });

        $resources = collect([
            (new ProjectResource)->forceFill(['id' => 'monitor_map', 'product' => 'monitor', 'status' => 'active']),
            (new ProjectResource)->forceFill(['id' => 'analytics_map', 'product' => 'analytics', 'status' => 'active']),
        ]);

        $destinations = (new ProjectResourceDestinations($registry))->forResources(new PlatformUser, $resources);

        $this->assertEquals(
            new ProjectResourceDestination(ProjectResourceDestinationState::Available, '/analytics/sites/7'),
            $destinations['analytics_map'],
        );
        $this->assertSame(ProjectResourceDestinationState::Unavailable, $destinations['monitor_map']->state);
    }
}
