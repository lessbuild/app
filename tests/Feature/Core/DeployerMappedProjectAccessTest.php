<?php

namespace Tests\Feature\Core;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ControlPlaneAccess;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Authored for the plan-completion run; exercises live membership decisions against the real Core schema. */
final class DeployerMappedProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PlatformUser $platformUser;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('platform:migrate', ['module' => 'core']);
        Queue::fake();
        config(['platform.products.deployer.auth_authority' => 'core']);

        $this->actor = User::factory()->create();
        $this->platformUser = PlatformUser::query()->create([
            'name' => 'Project member', 'email' => 'project-member@example.test',
            'email_normalized' => 'project-member@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->workspace = Workspace::query()->create([
            'owner_user_id' => $this->platformUser->getKey(), 'name' => 'Team', 'slug' => 'team', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->platformUser->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active',
        ]);
        $this->map('user', $this->actor->getKey(), 'user', $this->platformUser->getKey());
        $this->map('organization', $this->actor->current_organization_id, 'workspace', $this->workspace->getKey());
    }

    public function test_revocation_denies_native_project_environment_and_attached_resources_without_removing_local_roles(): void
    {
        [$project, $environment, $membership] = $this->mappedProject('checkout');
        $provider = $this->actor->providers()->create(['name' => 'Git', 'provider' => 'github', 'token' => 'secret', 'description' => 'Git']);
        $server = $this->actor->servers()->create(['name' => 'Production', 'provider_id' => $provider->id]);
        $website = $this->actor->websites()->create([
            'server_id' => $server->id, 'name' => 'Checkout', 'description' => 'Checkout', 'url' => 'checkout.example.test',
        ]);
        $repository = $this->actor->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Checkout',
            'description' => 'Checkout', 'url' => 'github.com/example/checkout.git',
        ]);
        $environment->update(['server_id' => $server->id, 'website_id' => $website->id]);
        $build = $repository->builds()->create(['environment_id' => $environment->id, 'status' => Build::STATUS_SUCCEEDED]);
        $access = app(DeployerProjectAccess::class);

        foreach ([$project, $environment, $server, $website, $repository, $build] as $resource) {
            $this->assertTrue($this->actor->can('view', $resource));
        }
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);

        foreach ([$project, $environment, $server, $website, $repository, $build] as $resource) {
            $this->assertFalse($this->actor->can('view', $resource));
        }
        $this->assertFalse($this->actor->can('createEnvironment', $project));
        $this->assertFalse($this->actor->can('manageConfiguration', $project));
        $this->assertFalse($this->actor->can('deploy', $repository));
        $this->assertFalse($this->actor->can('rollback', $build));
        $this->assertSame('owner', $project->organization->roleFor($this->actor));
        $this->assertSame(0, $this->actor->workspaceProjects()->count());
        $this->assertSame(0, $this->actor->workspaceServers()->count());
        $this->assertSame(0, $this->actor->workspaceWebsites()->count());
        $this->assertSame(0, $this->actor->workspaceRepositories()->count());
        $this->assertSame(0, $access->builds(Build::query(), $this->actor)->count());
    }

    public function test_collections_exclude_revoked_projects_before_limit_and_keep_unmapped_projects(): void
    {
        [$revoked, , $membership] = $this->mappedProject('a-revoked');
        [$allowed] = $this->mappedProject('b-allowed');
        $unmapped = $this->localProject('c-unmapped');
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->assertSame(2, $this->actor->workspaceProjects()->count());
        $this->assertSame([$allowed->id], $this->actor->workspaceProjects()->orderBy('name')->limit(1)->pluck('projects.id')->all());
        $this->assertTrue($this->actor->can('view', $unmapped));
        $this->assertFalse($this->actor->can('view', $revoked));

        $token = $this->actor->createToken('Legacy wide token', ['read'])->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/v1/projects');
        $response->assertOk();
        $this->assertEqualsCanonicalizing([$allowed->id, $unmapped->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_project_scoped_tokens_do_not_override_revoked_membership(): void
    {
        [$project, , $membership] = $this->mappedProject('target');
        $token = $this->actor->createToken('Project token', ['read', 'workspace:'.$project->organization_id, 'project:'.$project->id]);
        $this->actor->withAccessToken($token->accessToken);
        $request = Request::create('/api/v1/projects/'.$project->id);
        $request->setUserResolver(fn (): User => $this->actor);
        app(ControlPlaneAccess::class)->enforceProject($request, $project->id);

        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        try {
            app(ControlPlaneAccess::class)->enforceProject($request, $project->id);
            $this->fail('Token claims must not restore revoked canonical project membership.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_legacy_authority_preserves_local_project_permissions_after_core_revocation(): void
    {
        [$project, $environment, $membership] = $this->mappedProject('legacy');
        $membership->update(['status' => 'revoked', 'revoked_at' => now()]);
        config(['platform.products.deployer.auth_authority' => 'legacy']);

        $this->assertTrue($this->actor->can('view', $project));
        $this->assertTrue($this->actor->can('update', $environment));
        $this->assertSame([$project->id], $this->actor->workspaceProjects()->pluck('projects.id')->all());
    }

    public function test_core_product_revocation_denies_even_unmapped_source_projects_and_tokens(): void
    {
        $project = $this->localProject('unmapped');
        WorkspaceProductAccess::query()->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->assertFalse($this->actor->can('view', $project));
        $this->assertSame(0, $this->actor->workspaceProjects()->count());
        $token = $this->actor->createToken('Previously issued token', ['read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/projects')->assertForbidden();
    }

    /** @return array{Project, Environment, ProjectMembership} */
    private function mappedProject(string $name): array
    {
        $source = $this->localProject($name);
        $environment = $source->environments()->create(['name' => 'Production', 'slug' => 'production', 'type' => 'production']);
        $project = CoreProject::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'name' => $name, 'slug' => $name, 'status' => 'active',
        ]);
        $membership = ProjectMembership::query()->create([
            'project_id' => $project->getKey(), 'user_id' => $this->platformUser->getKey(), 'role' => 'member', 'status' => 'active',
        ]);
        ProjectProduct::query()->create(['project_id' => $project->getKey(), 'product' => 'deployer', 'status' => 'active']);
        ProjectResource::query()->create([
            'project_id' => $project->getKey(), 'product' => 'deployer', 'resource_type' => 'project',
            'resource_id' => (string) $source->id, 'status' => 'active',
        ]);

        return [$source, $environment, $membership];
    }

    private function localProject(string $name): Project
    {
        return $this->actor->currentOrganization->projects()->create(['name' => $name, 'slug' => $name, 'created_by' => $this->actor->id]);
    }

    private function map(string $entity, string|int $source, string $canonicalEntity, string $canonicalId): void
    {
        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => $entity, 'source_id' => (string) $source,
            'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
        ]);
    }
}
