<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectInfrastructureProvider;
use App\Core\Data\Projects\ProjectEnvironmentContext;
use App\Core\Data\Projects\ProjectEnvironmentContextState;
use App\Core\Data\Projects\ProjectInfrastructureEdge;
use App\Core\Data\Projects\ProjectInfrastructureNode;
use App\Core\Data\Projects\ProjectInfrastructureSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\ProjectInfrastructure;
use App\Core\Services\ProjectInfrastructureProviderRegistry;
use Closure;
use Illuminate\Database\LostConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProjectInfrastructureTest extends TestCase
{
    private PlatformUser $actor;

    private Project $project;

    private WorkspaceProductAccess $grant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.core.database' => ':memory:', 'platform.products.deployer.url' => 'https://deployer.example.test']);
        DB::purge('core');
        Artisan::call('platform:migrate', ['module' => 'core']);
        $this->actor = PlatformUser::query()->create(['name' => 'Owner', 'email' => 'map-owner@example.test', 'status' => 'active']);
        $workspace = Workspace::query()->create(['owner_user_id' => $this->actor->getKey(), 'name' => 'Team', 'slug' => 'map-team', 'status' => 'active']);
        $membership = WorkspaceMembership::query()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $this->actor->getKey(), 'role' => 'owner', 'status' => 'active']);
        $this->grant = WorkspaceProductAccess::query()->create(['membership_id' => $membership->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active']);
        $this->project = Project::query()->create(['workspace_id' => $workspace->getKey(), 'created_by_user_id' => $this->actor->getKey(), 'name' => 'Checkout', 'slug' => 'checkout', 'status' => 'active']);
        ProjectMembership::query()->create(['project_id' => $this->project->getKey(), 'user_id' => $this->actor->getKey(), 'role' => 'admin', 'status' => 'active']);
        ProjectProduct::query()->create(['project_id' => $this->project->getKey(), 'product' => 'deployer', 'status' => 'active']);
    }

    public function test_revoked_product_access_and_unavailable_environment_never_query_native_inventory(): void
    {
        $this->provider(fn () => $this->fail('Native resources must not be queried without current authority.'));
        $this->grant->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $this->assertSame([], app(ProjectInfrastructure::class)->forProject($this->actor, $this->project, ['deployer']));
        $this->grant->forceFill(['status' => 'active', 'revoked_at' => null])->save();
        $this->assertSame([], app(ProjectInfrastructure::class)->forProject($this->actor, $this->project, ['deployer'], new ProjectEnvironmentContext(ProjectEnvironmentContextState::Unavailable)));
    }

    public function test_projection_bounds_nodes_filters_dangling_edges_and_builds_links_on_the_product_host(): void
    {
        $nodes = [];
        for ($i = 0; $i < 105; $i++) {
            $nodes[] = new ProjectInfrastructureNode('node-'.$i, 'deployer', 'repository', 'Repository '.$i, 'https://untrusted.example/repositories/'.$i.'?organization_id=1');
        }
        $nodes[] = new ProjectInfrastructureNode('foreign', 'monitor', 'application', 'Must not appear');
        $this->provider(fn () => new ProjectInfrastructureSnapshot($nodes, [
            new ProjectInfrastructureEdge('node-0', 'node-1', 'deploys to'),
            new ProjectInfrastructureEdge('node-0', 'node-104', 'outside limit'),
            new ProjectInfrastructureEdge('node-0', 'foreign', 'foreign product'),
        ]));

        $snapshot = app(ProjectInfrastructure::class)->forProject($this->actor, $this->project, ['deployer'])['deployer'];

        $this->assertCount(100, $snapshot->nodes);
        $this->assertTrue($snapshot->truncated);
        $this->assertCount(1, $snapshot->edges);
        $this->assertSame('https://deployer.example.test/repositories/0?organization_id=1', $snapshot->nodes[0]->url);
        $this->assertSame('node-1', $snapshot->edges[0]->targetKey);
    }

    public function test_a_stale_archived_project_and_a_foreign_environment_do_not_query_native_inventory(): void
    {
        $this->provider(fn () => $this->fail('Stale project or environment authority must not query native resources.'));
        $foreignEnvironment = new ProjectEnvironment;
        $foreignEnvironment->forceFill(['id' => '01K8ZQEM7J5K0VRZHP9R3XZ108', 'project_id' => 'other-project', 'status' => 'active']);
        $context = new ProjectEnvironmentContext(ProjectEnvironmentContextState::Selected, $foreignEnvironment);
        $this->assertSame([], app(ProjectInfrastructure::class)->forProject($this->actor, $this->project, ['deployer'], $context));

        Project::query()->whereKey($this->project->getKey())->update(['status' => 'archived', 'archived_at' => now()]);
        $this->assertSame([], app(ProjectInfrastructure::class)->forProject($this->actor, $this->project, ['deployer']));
    }

    public function test_an_outage_hides_resource_details_and_renders_a_recoverable_state(): void
    {
        $this->provider(fn () => throw new LostConnectionException('Source unavailable'));
        $snapshots = app(ProjectInfrastructure::class)->forProject($this->actor, $this->project, ['deployer']);
        $this->assertFalse($snapshots['deployer']->available);
        $this->assertSame([], $snapshots['deployer']->nodes);
        $html = Blade::render('<x-signal.blocks.project-infrastructure :snapshots="$snapshots" />', compact('snapshots'));
        $this->assertStringContainsString('Infrastructure is temporarily unavailable', $html);
        $this->assertStringNotContainsString('Show resource list', $html);
    }

    public function test_the_visual_map_has_an_accessible_resource_list_and_only_visible_relationships(): void
    {
        $snapshots = ['deployer' => new ProjectInfrastructureSnapshot([
            new ProjectInfrastructureNode('repo', 'deployer', 'repository', 'Checkout source', 'https://deployer.example.test/repositories/1'),
            new ProjectInfrastructureNode('env', 'deployer', 'environment', 'Production'),
        ], [
            new ProjectInfrastructureEdge('repo', 'env', 'deploys to'),
            new ProjectInfrastructureEdge('repo', 'private', 'Private staging relationship'),
        ])];
        $html = Blade::render('<x-signal.blocks.project-infrastructure :snapshots="$snapshots" />', compact('snapshots'));

        $this->assertStringContainsString('Show resource list', $html);
        $this->assertStringContainsString('Infrastructure resource list', $html);
        $this->assertStringContainsString('deploys to', $html);
        $this->assertStringNotContainsString('Private staging relationship', $html);
        $this->assertStringContainsString('scope="col"', $html);
    }

    private function provider(Closure $callback): void
    {
        $registry = new ProjectInfrastructureProviderRegistry;
        $registry->register('deployer', new class($callback) implements ProjectInfrastructureProvider
        {
            public function __construct(private readonly Closure $callback) {}

            public function forProject(PlatformUser $user, Project $project, Collection $resources, ?ProjectEnvironmentContext $environmentContext = null): ProjectInfrastructureSnapshot
            {
                return ($this->callback)();
            }
        });
        app()->instance(ProjectInfrastructureProviderRegistry::class, $registry);
    }
}
