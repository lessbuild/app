<?php

namespace Tests\Feature\Core;

use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Modules\Deployer\Actions\Server\UpdateServerDisplayNameAction;
use App\Modules\Deployer\Http\Controllers\ServersController;
use App\Modules\Deployer\Http\Requests\ServerDisplayNameRequest;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\BuildInventoryQuery;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** Authored only; run with the plan-completion suite. Direct maps deliberately have no environment association. */
final class DeployerResourceDependencyAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PlatformUser $principal;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('platform:migrate', ['module' => 'core']);
        Queue::fake();
        config(['platform.products.deployer.auth_authority' => 'core']);
        $this->actor = User::factory()->create();
        $this->principal = PlatformUser::query()->create([
            'name' => 'Dependency owner', 'email' => 'dependency@example.test',
            'email_normalized' => 'dependency@example.test', 'status' => 'active',
        ]);
        $this->workspace = Workspace::query()->create([
            'owner_user_id' => $this->principal->getKey(), 'name' => 'Dependency workspace', 'slug' => 'dependency', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->principal->getKey(), 'role' => 'owner', 'status' => 'active',
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active',
        ]);
        foreach ([['user', $this->actor->id, 'user', $this->principal->getKey()], ['organization', $this->actor->current_organization_id, 'workspace', $this->workspace->getKey()]] as [$entity, $source, $target, $canonical]) {
            LegacyIdentityMap::query()->create([
                'source_product' => 'deployer', 'source_entity' => $entity, 'source_id' => (string) $source,
                'canonical_entity' => $target, 'canonical_id' => $canonical, 'status' => 'reconciled',
            ]);
        }
    }

    public function test_a_directly_denied_server_hides_websites_repositories_and_build_projections_and_denies_mutations(): void
    {
        $private = $this->resources('private');
        $visible = $this->resources('visible');
        $this->mapping('website', $private['website']); // An allowed site map cannot override its denied server.
        [, $membership] = $this->mapping('server', $private['server']);
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        $access = app(DeployerProjectAccess::class);

        $this->assertSame(0, Environment::query()->count());
        $this->assertSame([$visible['website']->id], $this->actor->workspaceWebsites()->pluck('websites.id')->all());
        $this->assertSame([$visible['repository']->id], $this->actor->workspaceRepositories()->pluck('repositories.id')->all());
        $this->assertSame([$visible['build']->id], $access->builds(Build::query(), $this->actor)->pluck('builds.id')->all());
        foreach (['view', 'update', 'delete', 'backup'] as $ability) {
            $this->assertFalse($this->actor->can($ability, $private['website']));
        }
        foreach (['view', 'deploy', 'delete'] as $ability) {
            $this->assertFalse($this->actor->can($ability, $private['repository']));
        }
        foreach (['view', 'redeploy', 'rollback', 'cancel'] as $ability) {
            $this->assertFalse($this->actor->can($ability, $private['build']));
        }
        $filters = array_fill_keys(['repository_id', 'website_id', 'server_id', 'provider_id', 'status', 'trigger', 'search', 'active', 'latest', 'date_from', 'date_to'], null);
        $projection = app(BuildInventoryQuery::class)->for($this->actor, $filters)->get();
        $this->assertSame([$visible['build']->id], $projection->modelKeys());
        $this->assertStringNotContainsString('private', $projection->toJson());
        $this->assertSame(1, app(BuildInventoryQuery::class)->metrics($this->actor, $filters)['total']);
    }

    public function test_a_direct_repository_denial_blocks_builds_and_shared_mutations_but_preserves_parent_views(): void
    {
        $resources = $this->resources('repository-denied');
        [, $membership] = $this->mapping('repository', $resources['repository']);
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->assertTrue($this->actor->can('view', $resources['website']));
        $this->assertTrue($this->actor->can('view', $resources['server']));
        $this->assertFalse($this->actor->can('view', $resources['build']));
        $this->assertFalse($this->actor->can('rollback', $resources['build']));
        $this->assertFalse($this->actor->can('update', $resources['website']));
        $this->assertFalse($this->actor->can('delete', $resources['website']));
        $this->assertFalse($this->actor->can('execute', $resources['server']));
        $this->assertFalse(app(DeployerProjectAccess::class)->canChangeProvider($this->actor, $resources['provider']));

        $membership->update(['status' => 'active', 'revoked_at' => null]);
        $this->assertTrue($this->actor->can('view', $resources['build']));
        $this->assertTrue($this->actor->can('update', $resources['website']));
    }

    public function test_retained_denied_sites_cannot_be_bypassed_by_server_or_provider_mutations(): void
    {
        $resources = $this->resources('retained-site');
        [, $membership] = $this->mapping('website', $resources['website']);
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        $resources['website']->delete();

        $this->assertTrue($this->actor->can('view', $resources['server']));
        $this->assertFalse($this->actor->can('update', $resources['server']));
        $this->assertFalse($this->actor->can('delete', $resources['server']));
        $this->assertFalse($this->actor->can('view', $resources['repository']));
        $this->assertFalse(app(DeployerProjectAccess::class)->canChangeProvider($this->actor, $resources['provider']));
    }

    public function test_a_denied_old_build_does_not_hide_its_repository_or_block_a_new_deployment_but_blocks_repository_deletion(): void
    {
        $resources = $this->resources('old-build');
        [, $membership] = $this->mapping('build', $resources['build']);
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->assertTrue($this->actor->can('view', $resources['repository']));
        $this->assertTrue($this->actor->can('deploy', $resources['repository']));
        $this->assertFalse($this->actor->can('delete', $resources['repository']));
        $this->assertFalse($this->actor->can('view', $resources['build']));
    }

    public function test_direct_provider_denial_cascades_to_dependents_and_legacy_authority_keeps_native_access(): void
    {
        $resources = $this->resources('provider-denied');
        [, $membership] = $this->mapping('provider', $resources['provider']);
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        foreach (['server', 'website', 'repository', 'build'] as $type) {
            $this->assertFalse($this->actor->can('view', $resources[$type]));
        }
        config(['platform.products.deployer.auth_authority' => 'legacy']);
        foreach (['server', 'website', 'repository', 'build'] as $type) {
            $this->assertTrue($this->actor->can('view', $resources[$type]));
        }
        $this->assertTrue($this->actor->can('deploy', $resources['repository']));
    }

    public function test_foreign_workspace_dependencies_are_not_treated_as_unmapped_local_resources(): void
    {
        $resources = $this->resources('local');
        $foreign = User::factory()->create();
        $provider = $foreign->providers()->create(['name' => 'Foreign provider', 'provider' => 'github', 'token' => 'secret', 'description' => 'Foreign']);
        $server = $foreign->servers()->create(['name' => 'Foreign server', 'provider_id' => $provider->id]);
        $resources['website']->update(['server_id' => $server->id]);

        $this->assertFalse($this->actor->can('view', $resources['website']));
        $this->assertFalse($this->actor->can('view', $resources['repository']));
        $this->assertFalse($this->actor->can('view', $resources['build']));
        $resources['website']->update(['server_id' => $resources['server']->id]);
        $resources['repository']->update(['provider_id' => $provider->id]);
        $this->assertFalse($this->actor->can('view', $resources['repository']));
    }

    public function test_environment_mutations_and_resource_operations_check_direct_placement_without_hiding_environment_view(): void
    {
        $resources = $this->resources('placement');
        $project = $this->actor->currentOrganization->projects()->create(['name' => 'Placement project', 'slug' => 'placement', 'created_by' => $this->actor->id]);
        $environment = $project->environments()->create([
            'name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'server_id' => $resources['server']->id, 'website_id' => $resources['website']->id,
        ]);
        $resource = $environment->resources()->create(['name' => 'Cache', 'type' => 'redis', 'status' => 'active']);
        [, $membership] = $this->mapping('server', $resources['server']);
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->assertTrue($this->actor->can('view', $environment));
        $this->assertFalse($this->actor->can('update', $environment));
        $this->assertFalse($this->actor->can('delete', $environment));
        $this->assertTrue($this->actor->can('view', $resource));
        $this->assertFalse($this->actor->can('manage', $resource));
    }

    public function test_build_mutations_check_a_separately_denied_environment_placement_even_when_its_repository_is_allowed(): void
    {
        $resources = $this->resources('normal-build');
        $placement = $this->resources('other-placement');
        $project = $this->actor->currentOrganization->projects()->create(['name' => 'Build placement', 'slug' => 'build-placement', 'created_by' => $this->actor->id]);
        $environment = $project->environments()->create([
            'name' => 'Staging', 'slug' => 'staging', 'type' => 'staging', 'server_id' => $placement['server']->id, 'website_id' => $placement['website']->id,
        ]);
        $resources['build']->update(['environment_id' => $environment->id]);
        [, $membership] = $this->mapping('server', $placement['server']);
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->assertTrue($this->actor->can('view', $resources['build']));
        $this->assertTrue($this->actor->can('updateNote', $resources['build']));
        foreach (['cancel', 'redeploy', 'promote', 'approve', 'rollback'] as $ability) {
            $this->assertFalse($this->actor->can($ability, $resources['build']));
        }
    }

    public function test_history_purpose_propagates_through_retained_dependencies_and_rechecks_current_membership(): void
    {
        $resources = $this->resources('archived');
        [$project, $membership] = $this->mapping('server', $resources['server']);
        $project->update(['status' => 'archived', 'archived_at' => now()]);
        ProjectProduct::query()->where('project_id', $project->getKey())->update(['status' => 'inactive']);
        ProjectResource::query()->where('project_id', $project->getKey())->update(['status' => 'archived']);
        $resources['website']->delete();
        $resources['repository']->delete();
        $access = app(DeployerProjectAccess::class);

        $this->assertSame([], $access->builds(Build::query(), $this->actor)->pluck('builds.id')->all());
        $this->assertSame([$resources['build']->id], $access->builds(Build::query(), $this->actor, ProjectResourceAccessPurpose::HistoricalExport)->pluck('builds.id')->all());
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->assertSame([], $access->builds(Build::query(), $this->actor, ProjectResourceAccessPurpose::HistoricalExport)->pluck('builds.id')->all());
    }

    public function test_dependency_collection_queries_grow_by_resource_types_not_resource_count_and_never_cache_permissions(): void
    {
        $resources = $this->resources('single');
        [, $membership] = $this->mapping('server', $resources['server']);
        $access = app(DeployerProjectAccess::class);
        $small = $this->queryCounts(fn () => $access->builds(Build::query(), $this->actor)->get());
        for ($i = 0; $i < 30; $i++) {
            $this->resources('batch-'.$i);
        }
        $large = $this->queryCounts(fn () => $access->builds(Build::query(), $this->actor)->get());
        foreach (['core', 'deployer'] as $connection) {
            $this->assertLessThanOrEqual($small[$connection] + 2, $large[$connection]);
        }
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->assertNotContains($resources['build']->id, $access->builds(Build::query(), $this->actor)->pluck('builds.id')->all());
        $this->assertSame(30, $access->builds(Build::query(), $this->actor)->count());
    }

    public function test_backup_credential_mutations_require_the_attached_sites_repository_authority(): void
    {
        $resources = $this->resources('backup-shared');
        $destination = $this->actor->currentOrganization->backupDestinations()->create([
            'created_by' => $this->actor->id, 'name' => 'Shared archive', 'endpoint' => 'https://archive.example.test',
            'bucket' => 'backups', 'access_key' => 'key', 'secret_key' => 'secret', 'repository_password' => 'password',
        ]);
        $destination->backups()->create(['website_id' => $resources['website']->id, 'status' => 'completed']);
        [, $membership] = $this->mapping('repository', $resources['repository']);
        $this->assertTrue($this->actor->can('update', $destination));
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->assertTrue($this->actor->can('view', $resources['website']));
        foreach (['update', 'delete', 'test'] as $ability) {
            $this->assertFalse($this->actor->can($ability, $destination));
        }
        $this->assertSame(1, $destination->backups()->count());
        config(['platform.products.deployer.auth_authority' => 'legacy']);
        $this->assertTrue($this->actor->can('update', $destination));
    }

    public function test_balancer_mutations_require_shared_authority_for_front_nodes_and_environment_placements_without_hiding_its_read_view(): void
    {
        $front = $this->resources('balancer-front');
        $node = $this->resources('balancer-node');
        $placement = $this->resources('balancer-placement');
        $organization = $this->actor->currentOrganization;
        $project = $organization->projects()->create(['name' => 'Balanced app', 'slug' => 'balanced-app', 'created_by' => $this->actor->id]);
        $environment = $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production',
            'server_id' => $placement['server']->id, 'website_id' => $placement['website']->id,
        ]);
        $balancer = $organization->loadBalancers()->create([
            'environment_id' => $environment->id, 'server_id' => $front['server']->id,
            'created_by' => $this->actor->id, 'hostname' => 'dependency-lb.example.test',
        ]);
        $balancer->nodes()->create(['server_id' => $node['server']->id]);
        $access = app(DeployerProjectAccess::class);
        $this->assertTrue($this->actor->can('manage', $balancer));

        foreach ([$front, $node, $placement] as $resources) {
            [, $membership] = $this->mapping('repository', $resources['repository']);
            $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
            $this->assertSame([$balancer->id], $access->loadBalancers($organization->loadBalancers(), $this->actor)->pluck('load_balancers.id')->all());
            $this->assertTrue($this->actor->can('view', $resources['server']));
            $this->assertFalse($this->actor->can('update', $resources['server']));
            $this->assertFalse($this->actor->can('manage', $balancer));
            $membership->update(['status' => 'active', 'revoked_at' => null]);
            $this->assertTrue($this->actor->can('manage', $balancer));
        }
    }

    public function test_a_permitted_server_label_remains_editable_when_a_child_repository_blocks_remote_operations(): void
    {
        $resources = $this->resources('display-label');
        [, $membership] = $this->mapping('repository', $resources['repository']);
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        $server = $resources['server'];
        $this->actingAs($this->actor);

        $this->assertTrue($this->actor->can('updateDisplayName', $server));
        $this->assertFalse($this->actor->can('update', $server));
        $this->assertFalse($this->actor->can('execute', $server));
        $this->assertSame($server, app(ServersController::class)->edit($server)->getData()['server']);

        $request = ServerDisplayNameRequest::create('/servers/'.$server->id, 'PATCH', ['display_name' => 'Friendly label']);
        $route = new Route('PATCH', '/servers/{server}', fn () => null);
        $route->bind($request);
        $route->setParameter('server', $server);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $this->actor);
        $request->setValidator(Validator::make($request->all(), $request->rules()));
        $this->assertTrue($request->authorize());
        app(ServersController::class)->update($request, $server, app(UpdateServerDisplayNameAction::class));

        $this->assertSame('Friendly label', $server->fresh()->display_name);
        $this->assertSame('display-label', $server->fresh()->name);
        $this->assertSame('revoked', $membership->fresh()->status);

        [, $serverMembership] = $this->mapping('server', $server);
        $serverMembership->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->assertFalse($request->authorize());
    }

    private function queryCounts(callable $operation): array
    {
        foreach (['core', 'deployer'] as $connection) {
            DB::connection($connection)->flushQueryLog();
            DB::connection($connection)->enableQueryLog();
        }
        try {
            $operation();

            return ['core' => count(DB::connection('core')->getQueryLog()), 'deployer' => count(DB::connection('deployer')->getQueryLog())];
        } finally {
            foreach (['core', 'deployer'] as $connection) {
                DB::connection($connection)->disableQueryLog();
                DB::connection($connection)->flushQueryLog();
            }
        }
    }

    private function resources(string $name): array
    {
        $provider = $this->actor->providers()->create(['name' => $name, 'provider' => 'github', 'token' => 'secret', 'description' => $name]);
        $server = $this->actor->servers()->create(['name' => $name, 'provider_id' => $provider->id]);
        $website = $this->actor->websites()->create(['server_id' => $server->id, 'name' => $name, 'description' => $name, 'url' => $name.'.example.test']);
        $repository = $this->actor->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => $name, 'description' => $name, 'url' => 'github.com/test/'.$name.'.git',
        ]);
        $build = $repository->builds()->create(['status' => Build::STATUS_SUCCEEDED]);

        return compact('provider', 'server', 'website', 'repository', 'build');
    }

    private function mapping(string $type, Model $resource): array
    {
        $name = $type.'-'.$resource->getKey();
        $project = CoreProject::query()->create(['workspace_id' => $this->workspace->getKey(), 'name' => $name, 'slug' => $name, 'status' => 'active']);
        $membership = ProjectMembership::query()->create(['project_id' => $project->getKey(), 'user_id' => $this->principal->getKey(), 'role' => 'member', 'status' => 'active']);
        ProjectProduct::query()->create(['project_id' => $project->getKey(), 'product' => 'deployer', 'status' => 'active']);
        ProjectResource::query()->create(['project_id' => $project->getKey(), 'product' => 'deployer', 'resource_type' => $type, 'resource_id' => (string) $resource->getKey(), 'status' => 'active']);

        return [$project, $membership];
    }
}
