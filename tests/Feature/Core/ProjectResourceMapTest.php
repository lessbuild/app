<?php

namespace Tests\Feature\Core;

use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectResource;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class ProjectResourceMapTest extends TestCase
{
    public function test_resource_map_shows_product_resources_and_their_supported_workflow(): void
    {
        $deployerResource = $this->resource('deployer', 'environment', 'Production');
        $monitorResource = $this->resource('monitor', 'environment', 'Production checks');
        $connection = (new ProjectConnection)->forceFill([
            'status' => 'pending',
            'capabilities' => ['deployment_context'],
        ]);
        $connection->setRelation('sourceResource', $deployerResource);
        $connection->setRelation('targetResource', $monitorResource);

        $html = $this->renderMap(
            collect([$deployerResource, $monitorResource]),
            collect([$connection]),
            [
                $deployerResource->getKey() => new ProjectResourceDestination(ProjectResourceDestinationState::Available, '/deployer/projects/31#environment-8'),
                $monitorResource->getKey() => new ProjectResourceDestination(ProjectResourceDestinationState::Available, '/monitor/environments/31/42'),
            ],
        );

        $this->assertStringContainsString('Resource map', $html);
        $this->assertStringContainsString('Deployer', $html);
        $this->assertStringContainsString('Production checks', $html);
        $this->assertStringContainsString('Deployment context', $html);
        $this->assertStringContainsString('Setup pending', $html);
        $this->assertStringContainsString('connects to', $html);
        $this->assertStringContainsString('href="/monitor/environments/31/42"', $html);
        $this->assertStringContainsString('Open Production checks in Monitor', $html);
        $this->assertStringContainsString('No resources linked yet.', $html);
    }

    public function test_resource_map_hides_unconfirmed_resource_details_and_workflow_endpoints(): void
    {
        $privateResource = $this->resource('monitor', 'environment', 'Private production');
        $connection = (new ProjectConnection)->forceFill([
            'status' => 'active',
            'capabilities' => ['incident_annotations'],
        ]);
        $connection->setRelation('sourceResource', $privateResource);
        $connection->setRelation('targetResource', $privateResource);

        $html = $this->renderMap(
            collect([$privateResource]),
            collect([$connection]),
            [$privateResource->getKey() => new ProjectResourceDestination(ProjectResourceDestinationState::AccessChanged)],
        );

        $this->assertStringContainsString('Resource details hidden', $html);
        $this->assertStringContainsString('Access to this mapped resource could not be confirmed.', $html);
        $this->assertStringContainsString('Some configured workflows are temporarily hidden', $html);
        $this->assertStringContainsString('0 workflows', $html);
        $this->assertStringNotContainsString('Private production', $html);
        $this->assertStringNotContainsString('href=', $html);
    }

    public function test_resource_map_hides_cached_names_during_product_outages(): void
    {
        $privateResource = $this->resource('monitor', 'environment', 'Private staging');

        $html = $this->renderMap(
            collect([$privateResource]),
            collect(),
            [$privateResource->getKey() => new ProjectResourceDestination(ProjectResourceDestinationState::Unavailable)],
        );

        $this->assertStringContainsString('The app is unavailable. Details stay hidden until access can be confirmed.', $html);
        $this->assertStringNotContainsString('Private staging', $html);
        $this->assertStringNotContainsString('environment', $html);
    }

    public function test_resource_map_has_a_clear_empty_state_for_workflows(): void
    {
        $html = $this->renderMap(collect(), collect());

        $this->assertStringContainsString('0 resources', $html);
        $this->assertStringContainsString('No workflows connect these resources yet.', $html);
    }

    private function resource(string $product, string $type, string $name): ProjectResource
    {
        $resource = (new ProjectResource)->forceFill([
            'id' => $product.'_resource',
            'product' => $product,
            'resource_type' => $type,
            'name' => $name,
            'status' => 'active',
        ]);
        $resource->setRelation('environment', null);

        return $resource;
    }

    private function renderMap($resources, $connections, array $resourceDestinations = [], int $hiddenConnectionCount = 0): string
    {
        return Blade::render(
            '<x-signal.ui.project-resource-map :products="$products" :resources="$resources" :connections="$connections" :resource-destinations="$resourceDestinations" :hidden-connection-count="$hiddenConnectionCount" />',
            [
                'products' => [
                    'deployer' => 'Deployer',
                    'monitor' => 'Monitor',
                    'analytics' => 'Analytics',
                ],
                'resources' => $resources,
                'connections' => $connections,
                'resourceDestinations' => $resourceDestinations,
                'hiddenConnectionCount' => $hiddenConnectionCount,
            ],
        );
    }
}
