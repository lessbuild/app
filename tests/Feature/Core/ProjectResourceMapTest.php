<?php

namespace Tests\Feature\Core;

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
            [$monitorResource->getKey() => '/monitor/applications/42'],
        );

        $this->assertStringContainsString('Resource map', $html);
        $this->assertStringContainsString('Deployer', $html);
        $this->assertStringContainsString('Production checks', $html);
        $this->assertStringContainsString('Deployment context', $html);
        $this->assertStringContainsString('Setup pending', $html);
        $this->assertStringContainsString('connects to', $html);
        $this->assertStringContainsString('href="/monitor/applications/42"', $html);
        $this->assertStringContainsString('Open Production checks in Monitor', $html);
        $this->assertStringContainsString('No resources linked yet.', $html);
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

    private function renderMap($resources, $connections, array $resourceDestinations = []): string
    {
        return Blade::render(
            '<x-signal.ui.project-resource-map :products="$products" :resources="$resources" :connections="$connections" :resource-destinations="$resourceDestinations" />',
            [
                'products' => [
                    'deployer' => 'Deployer',
                    'monitor' => 'Monitor',
                    'analytics' => 'Analytics',
                ],
                'resources' => $resources,
                'connections' => $connections,
                'resourceDestinations' => $resourceDestinations,
            ],
        );
    }
}
