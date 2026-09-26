<?php

namespace Tests\Feature\Core;

use App\Core\Data\Projects\ProjectEnvironmentContext;
use App\Core\Data\Projects\ProjectEnvironmentContextState;
use App\Core\Data\Projects\ProjectInfrastructureSnapshot;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Project as DeployerProject;
use App\Modules\Deployer\Models\User as DeployerUser;
use App\Modules\Deployer\Services\Core\DeployerProjectInfrastructureProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DeployerProjectInfrastructureProviderTest extends TestCase
{
    use RefreshDatabase;

    private DeployerUser $sourceUser;

    private PlatformUser $platformUser;

    private Workspace $workspace;

    private CoreProject $project;

    private DeployerProject $sourceProject;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('platform:migrate', ['module' => 'core']);
        Artisan::call('platform:migrate', ['module' => 'deployer']);
        config(['platform.products.deployer.auth_authority' => 'core']);

        $this->sourceUser = DeployerUser::factory()->create();
        $this->platformUser = PlatformUser::query()->create([
            'name' => 'Mapped user', 'email' => 'mapped-infra@example.test',
            'email_normalized' => 'mapped-infra@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->workspace = Workspace::query()->create([
            'owner_user_id' => $this->platformUser->getKey(), 'name' => 'Mapped workspace', 'slug' => 'mapped-infra', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->platformUser->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active',
        ]);
        $this->map('user', $this->sourceUser->getKey(), 'user', $this->platformUser->getKey());
        $this->map('organization', $this->sourceUser->current_organization_id, 'workspace', $this->workspace->getKey());

        $this->sourceProject = $this->sourceUser->currentOrganization->projects()->create([
            'name' => 'Mapped project', 'slug' => 'mapped-project', 'created_by' => $this->sourceUser->getKey(),
        ]);
        $this->project = CoreProject::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'name' => 'Mapped project', 'slug' => 'mapped-project', 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $this->project->getKey(), 'user_id' => $this->platformUser->getKey(),
            'role' => 'owner', 'status' => 'active',
        ]);
        ProjectProduct::query()->create(['project_id' => $this->project->getKey(), 'product' => 'deployer', 'status' => 'active']);
        ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'product' => 'deployer', 'resource_type' => 'project',
            'resource_id' => (string) $this->sourceProject->getKey(), 'status' => 'active',
        ]);
    }

    public function test_projects_only_exact_mapped_authorized_environments_and_native_relationships(): void
    {
        [$environment, $canonical] = $this->environment('production');
        $otherProject = $this->sourceUser->currentOrganization->projects()->create([
            'name' => 'Unmapped sibling', 'slug' => 'unmapped-sibling', 'created_by' => $this->sourceUser->getKey(),
        ]);
        $foreignEnvironment = $otherProject->environments()->create(['name' => 'Private staging', 'slug' => 'private-staging', 'type' => 'staging']);
        $provider = $this->sourceUser->providers()->create(['name' => 'Git provider', 'provider' => 'github', 'token' => 'credential', 'description' => 'Provider']);
        $server = $this->sourceUser->servers()->create(['name' => 'Production server', 'provider_id' => $provider->getKey()]);
        $website = $this->sourceUser->websites()->create([
            'server_id' => $server->getKey(), 'name' => 'Production website', 'description' => 'Site', 'url' => 'production.example.test',
        ]);
        $repository = $this->sourceUser->repositories()->create([
            'provider_id' => $provider->getKey(), 'website_id' => $website->getKey(), 'name' => 'Application source',
            'description' => 'Source', 'url' => 'github.com/example/app.git',
        ]);
        $environment->update(['server_id' => $server->getKey(), 'website_id' => $website->getKey()]);
        $build = $repository->builds()->create(['environment_id' => $environment->getKey(), 'status' => Build::STATUS_SUCCEEDED]);
        $foreignEnvironment->update(['server_id' => $server->getKey()]);

        $snapshot = $this->snapshot();
        $kinds = collect($snapshot->nodes)->pluck('kind')->all();
        $this->assertContains('environment', $kinds);
        $this->assertContains('server', $kinds);
        $this->assertContains('website', $kinds);
        $this->assertContains('repository', $kinds);
        $this->assertContains('deployment', $kinds);
        $this->assertNotContains('Private staging', collect($snapshot->nodes)->pluck('label')->all());
        $this->assertSame([$canonical->getKey()], collect($snapshot->nodes)->where('kind', 'environment')->pluck('environmentId')->all());
        $this->assertNotEmpty($snapshot->edges);
        foreach ($snapshot->edges as $edge) {
            $this->assertContains($edge->sourceKey, collect($snapshot->nodes)->pluck('key')->all());
            $this->assertContains($edge->targetKey, collect($snapshot->nodes)->pluck('key')->all());
        }
        $this->assertNotNull($build->fresh());
    }

    public function test_selected_environment_limits_inventory_and_unavailable_selection_returns_empty(): void
    {
        [$first, $firstCanonical] = $this->environment('production');
        [$second, $secondCanonical] = $this->environment('staging');

        $snapshot = $this->snapshot(new ProjectEnvironmentContext(ProjectEnvironmentContextState::Selected, $firstCanonical));
        $this->assertSame([$firstCanonical->getKey()], collect($snapshot->nodes)->where('kind', 'environment')->pluck('environmentId')->all());

        $unavailable = $this->snapshot(new ProjectEnvironmentContext(ProjectEnvironmentContextState::Unavailable, requestedId: 'missing'));
        $this->assertSame([], $unavailable->nodes);
        $this->assertSame([], $unavailable->edges);
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertNotSame($firstCanonical->getKey(), $secondCanonical->getKey());
    }

    public function test_native_parent_or_direct_resource_revocation_removes_inventory(): void
    {
        [$environment, $canonical] = $this->environment('production');
        $provider = $this->sourceUser->providers()->create(['name' => 'Direct mapping provider', 'provider' => 'github', 'token' => 'credential', 'description' => 'Provider']);
        $server = $this->sourceUser->servers()->create(['name' => 'Restricted server', 'provider_id' => $provider->getKey()]);
        $environment->update(['server_id' => $server->getKey()]);
        $serverMapping = ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'environment_id' => $canonical->getKey(), 'product' => 'deployer',
            'resource_type' => 'server', 'resource_id' => (string) $server->getKey(), 'name' => 'Restricted server', 'status' => 'revoked',
        ]);
        $revoked = $this->snapshot();
        $this->assertContains('environment', collect($revoked->nodes)->pluck('kind')->all());
        $this->assertNotContains('server', collect($revoked->nodes)->pluck('kind')->all());

        $serverMapping->update(['status' => 'active']);
        $this->assertContains('server', collect($this->snapshot()->nodes)->pluck('kind')->all());
        $membership = WorkspaceMembership::query()->where('workspace_id', $this->workspace->getKey())->firstOrFail();
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->assertSame([], $this->snapshot()->nodes);
        $this->assertNotNull($environment->fresh());
        $this->assertNotNull($canonical->fresh());
    }

    public function test_shared_server_does_not_expose_edges_to_unmapped_private_environment(): void
    {
        [$environment] = $this->environment('production');
        $privateProject = $this->sourceUser->currentOrganization->projects()->create([
            'name' => 'Private project', 'slug' => 'private-project', 'created_by' => $this->sourceUser->getKey(),
        ]);
        $privateEnvironment = $privateProject->environments()->create(['name' => 'Secret env', 'slug' => 'secret-env', 'type' => 'staging']);
        $provider = $this->sourceUser->providers()->create(['name' => 'Shared provider', 'provider' => 'github', 'token' => 'credential', 'description' => 'Provider']);
        $server = $this->sourceUser->servers()->create(['name' => 'Shared host', 'provider_id' => $provider->getKey()]);
        $environment->update(['server_id' => $server->getKey()]);
        $privateEnvironment->update(['server_id' => $server->getKey()]);

        $snapshot = $this->snapshot();
        $this->assertSame(1, collect($snapshot->nodes)->where('kind', 'server')->count());
        $this->assertSame(1, collect($snapshot->edges)->where('label', 'runs on')->count());
        $this->assertNotContains('Secret env', collect($snapshot->nodes)->pluck('label')->all());
    }

    public function test_source_database_outage_returns_unavailable_snapshot(): void
    {
        $database = config('database.connections.deployer.database');
        try {
            DB::connection('deployer')->disconnect();
            DB::purge('deployer');
            config(['database.connections.deployer.database' => '/path/which/does/not/exist/deployer.sqlite']);

            $snapshot = $this->snapshot();
            $this->assertFalse($snapshot->available);
            $this->assertSame([], $snapshot->nodes);
            $this->assertSame([], $snapshot->edges);
        } finally {
            config(['database.connections.deployer.database' => $database]);
            DB::purge('deployer');
        }
    }

    public function test_recent_deployment_history_is_bounded_and_discloses_truncation(): void
    {
        [$environment] = $this->environment('production');
        $provider = $this->sourceUser->providers()->create(['name' => 'Build provider', 'provider' => 'github', 'token' => 'credential', 'description' => 'Provider']);
        $server = $this->sourceUser->servers()->create(['name' => 'Build server', 'provider_id' => $provider->getKey()]);
        $website = $this->sourceUser->websites()->create([
            'server_id' => $server->getKey(), 'name' => 'Build website', 'description' => 'Site', 'url' => 'build.example.test',
        ]);
        $repository = $this->sourceUser->repositories()->create([
            'provider_id' => $provider->getKey(), 'website_id' => $website->getKey(), 'name' => 'Build source',
            'description' => 'Source', 'url' => 'github.com/example/build.git',
        ]);
        $environment->update(['server_id' => $server->getKey(), 'website_id' => $website->getKey()]);
        for ($index = 0; $index < 14; $index++) {
            $repository->builds()->create(['environment_id' => $environment->getKey(), 'status' => Build::STATUS_SUCCEEDED]);
        }

        $snapshot = $this->snapshot();
        $this->assertSame(10, collect($snapshot->nodes)->where('kind', 'deployment')->count());
        $this->assertTrue($snapshot->truncated);
    }

    public function test_inventory_and_recent_build_history_are_bounded_with_truncation_disclosure(): void
    {
        for ($index = 0; $index < 105; $index++) {
            $this->environment('environment-'.$index);
        }

        $snapshot = $this->snapshot();
        $this->assertLessThanOrEqual(100, count($snapshot->nodes));
        $this->assertTrue($snapshot->truncated);
    }

    /** @return array{Environment, ProjectEnvironment, ProjectResource} */
    private function environment(string $name): array
    {
        $environment = $this->sourceProject->environments()->create(['name' => ucfirst($name), 'slug' => $name, 'type' => 'production']);
        $canonical = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'name' => ucfirst($name), 'slug' => $name, 'environment_type' => 'production', 'status' => 'active',
        ]);
        $mapping = ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'environment_id' => $canonical->getKey(), 'product' => 'deployer',
            'resource_type' => 'environment', 'resource_id' => (string) $environment->getKey(), 'name' => ucfirst($name), 'status' => 'active',
        ]);
        $this->map('environment', $environment->getKey(), 'project_environment', $canonical->getKey());

        return [$environment, $canonical, $mapping];
    }

    private function snapshot(?ProjectEnvironmentContext $context = null): ProjectInfrastructureSnapshot
    {
        $resources = ProjectResource::query()->where('project_id', $this->project->getKey())->where('product', 'deployer')->where('status', 'active')->get();

        return app(DeployerProjectInfrastructureProvider::class)->forProject($this->platformUser, $this->project, $resources, $context);
    }

    private function map(string $entity, string|int $source, string $canonicalEntity, string $canonicalId): void
    {
        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => $entity, 'source_id' => (string) $source,
            'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
        ]);
    }
}
