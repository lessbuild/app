<?php

namespace Tests\Feature\Core;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectEnvironment as CoreEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Project as DeployerProject;
use App\Modules\Deployer\Models\User as DeployerUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class WorkspaceDeployerDeploymentControlsTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformOwner;

    private Workspace $workspace;

    private CoreProject $project;

    private CoreEnvironment $environment;

    private WorkspaceProductAccess $grant;

    private DeployerUser $nativeOwner;

    private Organization $organization;

    private DeployerProject $nativeProject;

    private Environment $nativeEnvironment;

    private ProjectResource $environmentMapping;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('platform:migrate', ['module' => 'core']);
        config([
            'platform.products.deployer.enabled' => true,
            'platform.products.deployer.auth_authority' => 'core',
        ]);

        $this->nativeOwner = DeployerUser::factory()->create();
        $this->organization = $this->nativeOwner->currentOrganization;
        $this->platformOwner = PlatformUser::query()->create([
            'name' => 'Workspace owner', 'email' => 'deployment-owner@example.test',
            'email_normalized' => 'deployment-owner@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->workspace = Workspace::query()->create([
            'owner_user_id' => $this->platformOwner->getKey(), 'name' => 'Deployment workspace',
            'slug' => 'deployment-workspace', 'status' => 'active',
        ]);
        $workspaceMembership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->platformOwner->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->grant = WorkspaceProductAccess::query()->create([
            'membership_id' => $workspaceMembership->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active',
        ]);

        $this->project = CoreProject::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'created_by_user_id' => $this->platformOwner->getKey(),
            'name' => 'Checkout', 'slug' => 'checkout', 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $this->project->getKey(), 'user_id' => $this->platformOwner->getKey(), 'role' => 'admin', 'status' => 'active',
        ]);
        ProjectProduct::query()->create(['project_id' => $this->project->getKey(), 'product' => 'deployer', 'status' => 'active']);
        $this->environment = CoreEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'name' => 'Production', 'slug' => 'production',
            'environment_type' => 'production', 'status' => 'active',
        ]);

        $this->nativeProject = $this->organization->projects()->create([
            'name' => 'Checkout', 'slug' => 'checkout', 'created_by' => $this->nativeOwner->getKey(),
        ]);
        $this->nativeEnvironment = $this->nativeProject->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production',
            'deployment_locked_at' => now(), 'deployment_lock_reason' => 'Planned database change',
            'deployment_window_days' => [1, 3], 'deployment_window_start' => '09:00',
            'deployment_window_end' => '17:00', 'deployment_window_timezone' => 'UTC',
            'deployment_strategy' => 'canary', 'rolling_pause_seconds' => 5, 'automatic_rollback' => true,
        ]);

        $this->map('user', $this->nativeOwner->getKey(), 'user', $this->platformOwner->getKey());
        $this->map('organization', $this->organization->getKey(), 'workspace', $this->workspace->getKey());
        ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'product' => 'deployer', 'resource_type' => 'project',
            'resource_id' => (string) $this->nativeProject->getKey(), 'status' => 'active',
        ]);
        $this->environmentMapping = ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'environment_id' => $this->environment->getKey(),
            'product' => 'deployer', 'resource_type' => 'environment',
            'resource_id' => (string) $this->nativeEnvironment->getKey(), 'status' => 'active',
        ]);
    }

    public function test_owner_can_read_safe_current_controls_and_update_the_exact_mapped_environment(): void
    {
        $this->actingAs($this->platformOwner, 'platform')
            ->get($this->showUrl())
            ->assertOk()
            ->assertSee('Planned database change')
            ->assertSee('canary')
            ->assertSee('Enable automatic rollback');

        $response = $this->actingAs($this->platformOwner, 'platform')
            ->from($this->showUrl())
            ->patch($this->updateUrl(), [
                'deployment_locked' => '0',
                'deployment_lock_reason' => '',
                'deployment_window_enabled' => '1',
                'deployment_window_days' => ['2', '4'],
                'deployment_window_start' => '10:30',
                'deployment_window_end' => '18:00',
                'deployment_window_timezone' => 'Europe/London',
                'deployment_strategy' => 'rolling',
                'rolling_pause_seconds' => '10',
                'automatic_rollback' => '0',
            ]);

        $response->assertRedirect($this->showUrl());
        $this->assertNull($this->nativeEnvironment->fresh()->deployment_locked_at);
        $this->assertNull($this->nativeEnvironment->fresh()->deployment_lock_reason);
        $this->assertSame([2, 4], array_map('intval', $this->nativeEnvironment->fresh()->deployment_window_days));
        $this->assertSame('10:30', substr((string) $this->nativeEnvironment->fresh()->deployment_window_start, 0, 5));
        $this->assertSame('Europe/London', $this->nativeEnvironment->fresh()->deployment_window_timezone);
        $this->assertSame('rolling', $this->nativeEnvironment->fresh()->deployment_strategy);
        $this->assertSame(10, $this->nativeEnvironment->fresh()->rolling_pause_seconds);
        $this->assertFalse($this->nativeEnvironment->fresh()->automatic_rollback);
        $this->assertSame($this->organization->getKey(), $this->nativeOwner->fresh()->current_organization_id);
    }

    public function test_authorized_core_member_with_native_deploy_permission_can_manage_controls(): void
    {
        $platformDeveloper = PlatformUser::query()->create([
            'name' => 'Deployment developer', 'email' => 'deployment-developer@example.test',
            'email_normalized' => 'deployment-developer@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $workspaceMembership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $platformDeveloper->getKey(),
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $workspaceMembership->getKey(), 'product' => 'deployer', 'role' => 'member', 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $this->project->getKey(), 'user_id' => $platformDeveloper->getKey(), 'role' => 'member', 'status' => 'active',
        ]);
        $nativeDeveloper = DeployerUser::factory()->create();
        $this->organization->members()->attach($nativeDeveloper->getKey(), ['role' => 'developer']);
        $this->map('user', $nativeDeveloper->getKey(), 'user', $platformDeveloper->getKey());

        $personalOrganizationId = $nativeDeveloper->current_organization_id;
        $this->actingAs($platformDeveloper, 'platform')
            ->get($this->showUrl())
            ->assertOk();
        $this->actingAs($platformDeveloper, 'platform')
            ->from($this->showUrl())
            ->patch($this->updateUrl(), [
                'deployment_locked' => '0', 'deployment_lock_reason' => '',
                'deployment_window_enabled' => '0', 'deployment_strategy' => 'blue_green',
                'rolling_pause_seconds' => '2', 'automatic_rollback' => '0',
            ])
            ->assertRedirect($this->showUrl());
        $this->assertSame($personalOrganizationId, $nativeDeveloper->fresh()->current_organization_id);
    }

    public function test_foreign_source_environment_mapping_is_rejected(): void
    {
        $foreignOwner = DeployerUser::factory()->create();
        $foreignProject = $foreignOwner->currentOrganization->projects()->create([
            'name' => 'Foreign app', 'slug' => 'foreign-app', 'created_by' => $foreignOwner->getKey(),
        ]);
        $foreignEnvironment = $foreignProject->environments()->create([
            'name' => 'Foreign production', 'slug' => 'production', 'type' => 'production',
        ]);
        $this->environmentMapping->update(['resource_id' => (string) $foreignEnvironment->getKey()]);

        $this->actingAs($this->platformOwner, 'platform')
            ->get($this->showUrl())
            ->assertNotFound();
    }

    public function test_stale_environment_mapping_is_rejected(): void
    {
        $this->environmentMapping->update(['resource_id' => '999999999']);

        $this->actingAs($this->platformOwner, 'platform')
            ->get($this->showUrl())
            ->assertNotFound();
    }

    public function test_revoked_core_product_grant_is_rechecked_for_the_page(): void
    {
        $this->grant->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->actingAs($this->platformOwner, 'platform')
            ->get($this->showUrl())
            ->assertNotFound();
    }

    public function test_unverified_native_actor_is_denied_like_the_native_verified_route(): void
    {
        $this->nativeOwner->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->platformOwner, 'platform')
            ->get($this->showUrl())
            ->assertForbidden();
    }

    public function test_native_window_cross_field_and_enum_validation_are_preserved(): void
    {
        $validBase = [
            'deployment_locked' => '0', 'deployment_lock_reason' => '',
            'deployment_window_enabled' => '1', 'deployment_strategy' => 'blue_green',
            'rolling_pause_seconds' => '2', 'automatic_rollback' => '0',
        ];
        $this->actingAs($this->platformOwner, 'platform')
            ->from($this->showUrl())
            ->patch($this->updateUrl(), $validBase)
            ->assertRedirect($this->showUrl())
            ->assertSessionHasErrors(['deployment_window_days']);

        $invalidEnum = [
            ...$validBase,
            'deployment_window_enabled' => '0',
            'deployment_strategy' => 'instant',
            'rolling_pause_seconds' => '3',
        ];
        $this->actingAs($this->platformOwner, 'platform')
            ->from($this->showUrl())
            ->patch($this->updateUrl(), $invalidEnum)
            ->assertRedirect($this->showUrl())
            ->assertSessionHasErrors(['deployment_strategy', 'rolling_pause_seconds']);

        $this->assertNotNull($this->nativeEnvironment->fresh()->deployment_locked_at);
        $this->assertSame('canary', $this->nativeEnvironment->fresh()->deployment_strategy);
    }

    private function showUrl(): string
    {
        return route('core.projects.deployer-deployment-controls.show', [$this->workspace, $this->project, $this->environment]);
    }

    private function updateUrl(): string
    {
        return route('core.projects.deployer-deployment-controls.update', [$this->workspace, $this->project, $this->environment]);
    }

    private function map(string $sourceEntity, string|int $sourceId, string $canonicalEntity, string $canonicalId): void
    {
        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => $sourceEntity, 'source_id' => (string) $sourceId,
            'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
        ]);
    }
}
