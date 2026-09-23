<?php

namespace Tests\Feature\Core;

use App\Core\Data\Projects\ProjectEnvironmentContext;
use App\Core\Data\Projects\ProjectEnvironmentContextState;
use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Services\BuildProjectContextNavigation;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProjectContextNavigationTest extends TestCase
{
    public function test_mapped_app_links_keep_canonical_project_and_environment_context(): void
    {
        $projectId = (string) Str::ulid();
        $environmentId = (string) Str::ulid();
        $resource = $this->resource($projectId, 'deployer', 'environment', $environmentId);
        $environment = (new ProjectEnvironment)->forceFill([
            'id' => $environmentId,
            'project_id' => $projectId,
            'name' => 'Staging',
        ]);
        $project = $this->projectWithResources($projectId, [$resource]);
        $workspace = (new Workspace)->forceFill(['id' => (string) Str::ulid()]);
        $destinationUrl = 'https://deployer.example/projects/42?tab=deployments#environment-12';

        $navigation = app(BuildProjectContextNavigation::class)->forProject(
            $workspace,
            $project,
            new ProjectEnvironmentContext(ProjectEnvironmentContextState::Selected, $environment, $environmentId),
            [(string) $resource->getKey() => new ProjectResourceDestination(ProjectResourceDestinationState::Available, $destinationUrl)],
        );

        parse_str((string) parse_url($navigation['urls']['deployer'], PHP_URL_QUERY), $query);

        $this->assertSame($destinationUrl, $navigation['links']['deployer']);
        $this->assertSame($projectId, $query['context_project']);
        $this->assertSame($environmentId, $query['context_environment']);
        $this->assertSame('deployments', $query['tab']);
        $this->assertSame('environment-12', parse_url($navigation['urls']['deployer'], PHP_URL_FRAGMENT));
        $this->assertNull($navigation['status']['deployer']);
    }

    public function test_duplicate_active_mappings_fall_back_to_core_instead_of_choosing_one(): void
    {
        $projectId = (string) Str::ulid();
        $environmentId = (string) Str::ulid();
        $first = $this->resource($projectId, 'monitor', 'environment', $environmentId);
        $second = $this->resource($projectId, 'monitor', 'environment', $environmentId);
        $environment = (new ProjectEnvironment)->forceFill([
            'id' => $environmentId,
            'project_id' => $projectId,
            'name' => 'Production',
        ]);
        $workspace = (new Workspace)->forceFill(['id' => (string) Str::ulid()]);
        $project = $this->projectWithResources($projectId, [$first, $second]);

        $navigation = app(BuildProjectContextNavigation::class)->forProject(
            $workspace,
            $project,
            new ProjectEnvironmentContext(ProjectEnvironmentContextState::Selected, $environment, $environmentId),
            [
                (string) $first->getKey() => new ProjectResourceDestination(ProjectResourceDestinationState::Available, 'https://monitor.example/environments/4'),
                (string) $second->getKey() => new ProjectResourceDestination(ProjectResourceDestinationState::Available, 'https://monitor.example/environments/9'),
            ],
        );

        $this->assertNull($navigation['links']['monitor']);
        $this->assertSame('Multiple mappings need review', $navigation['status']['monitor']);
        $this->assertStringContainsString('context_environment='.$environmentId, $navigation['urls']['monitor']);
        $this->assertStringContainsString('context_project='.$projectId, $navigation['urls']['monitor']);
        $this->assertStringNotContainsString('monitor.example', $navigation['urls']['monitor']);
    }

    public function test_unmapped_product_keeps_its_dashboard_navigation_when_no_environment_is_selected(): void
    {
        $projectId = (string) Str::ulid();
        $workspace = (new Workspace)->forceFill(['id' => (string) Str::ulid()]);
        $project = $this->projectWithResources($projectId, []);

        $navigation = app(BuildProjectContextNavigation::class)->forProject(
            $workspace,
            $project,
            new ProjectEnvironmentContext(ProjectEnvironmentContextState::All),
            [],
        );

        $this->assertNull($navigation['links']['deployer']);
        $this->assertSame(route('dashboard'), $navigation['urls']['deployer']);
        $this->assertSame('Not mapped to this project', $navigation['status']['deployer']);
    }

    public function test_unavailable_environment_is_preserved_without_linking_to_any_product(): void
    {
        $projectId = (string) Str::ulid();
        $requestedEnvironmentId = (string) Str::ulid();
        $workspace = (new Workspace)->forceFill(['id' => (string) Str::ulid()]);
        $project = $this->projectWithResources($projectId, []);

        $navigation = app(BuildProjectContextNavigation::class)->forProject(
            $workspace,
            $project,
            new ProjectEnvironmentContext(ProjectEnvironmentContextState::Unavailable, requestedId: $requestedEnvironmentId),
            [],
        );

        foreach (['deployer', 'monitor', 'analytics'] as $product) {
            $this->assertNull($navigation['links'][$product]);
            $this->assertSame('Selected environment unavailable', $navigation['status'][$product]);
            $this->assertStringContainsString('context_environment='.$requestedEnvironmentId, $navigation['urls'][$product]);
        }
    }

    /** @param list<ProjectResource> $resources */
    private function projectWithResources(string $projectId, array $resources): Project
    {
        $project = (new Project)->forceFill(['id' => $projectId, 'workspace_id' => (string) Str::ulid()]);
        $project->setRelation('resources', collect($resources));

        return $project;
    }

    private function resource(string $projectId, string $product, string $type, string $environmentId): ProjectResource
    {
        return (new ProjectResource)->forceFill([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'environment_id' => $environmentId,
            'product' => $product,
            'resource_type' => $type,
            'resource_id' => (string) random_int(1, 999),
            'status' => 'active',
        ]);
    }
}
