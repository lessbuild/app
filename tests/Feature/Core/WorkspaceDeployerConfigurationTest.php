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
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\Project as DeployerProject;
use App\Modules\Deployer\Models\User as DeployerUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class WorkspaceDeployerConfigurationTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('platform:migrate', ['module' => 'core']);
        config([
            'platform.products.deployer.enabled' => true,
            'platform.products.deployer.auth_authority' => 'core',
            'billing.enforce_entitlements' => false,
        ]);

        $this->nativeOwner = DeployerUser::factory()->create();
        $this->organization = $this->nativeOwner->currentOrganization;
        $this->platformOwner = PlatformUser::query()->create([
            'name' => 'Workspace owner', 'email' => 'configuration-owner@example.test',
            'email_normalized' => 'configuration-owner@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->workspace = Workspace::query()->create([
            'owner_user_id' => $this->platformOwner->getKey(), 'name' => 'Configuration workspace',
            'slug' => 'configuration-workspace', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->platformOwner->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->grant = WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active',
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
            'preview_enabled' => false, 'preview_domain' => 'previews.example.test', 'preview_ttl_hours' => 72,
        ]);
        $this->nativeEnvironment = $this->nativeProject->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'runtime_type' => 'php', 'runtime_version' => '8.3',
            'build_command' => 'echo CONFIGURATION_COMMAND_SENTINEL',
            'start_command' => 'php artisan serve --token CONFIGURATION_SECRET_SENTINEL',
            'minimum_replicas' => 1, 'maximum_replicas' => 1,
        ]);

        $this->map('user', $this->nativeOwner->getKey(), 'user', $this->platformOwner->getKey());
        $this->map('organization', $this->organization->getKey(), 'workspace', $this->workspace->getKey());
        $this->mapProject($this->project, $this->nativeProject);
        ProjectResource::query()->create([
            'project_id' => $this->project->getKey(), 'environment_id' => $this->environment->getKey(),
            'product' => 'deployer', 'resource_type' => 'environment',
            'resource_id' => (string) $this->nativeEnvironment->getKey(), 'status' => 'active',
        ]);
    }

    public function test_owner_can_edit_mapped_preview_and_environment_settings_without_rendering_commands_or_secrets(): void
    {
        $this->actingAs($this->platformOwner, 'platform')
            ->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Checkout');

        $this->actingAs($this->platformOwner, 'platform')
            ->get($this->projectUrl())
            ->assertOk()
            ->assertSee('previews.example.test')
            ->assertSee('Production')
            ->assertDontSee('CONFIGURATION_COMMAND_SENTINEL')
            ->assertDontSee('CONFIGURATION_SECRET_SENTINEL');

        $this->actingAs($this->platformOwner, 'platform')
            ->get($this->environmentUrl())
            ->assertOk()
            ->assertSee('8.3')
            ->assertSee('main')
            ->assertSee('Manage variables in Deployer')
            ->assertSee('#environment-'.$this->nativeEnvironment->getKey().'-variables', false)
            ->assertDontSee('name="type"', false)
            ->assertDontSee('CONFIGURATION_COMMAND_SENTINEL')
            ->assertDontSee('CONFIGURATION_SECRET_SENTINEL');

        $this->actingAs($this->platformOwner, 'platform')
            ->patch($this->previewUpdateUrl(), [
                'preview_enabled' => '0',
                'preview_domain' => 'https://previews.example.test/',
                'preview_ttl_hours' => '48',
            ])
            ->assertRedirect($this->projectUrl());
        $this->assertFalse((bool) $this->nativeProject->fresh()->preview_enabled);
        $this->assertSame('previews.example.test', $this->nativeProject->fresh()->preview_domain);
        $this->assertSame(48, (int) $this->nativeProject->fresh()->preview_ttl_hours);

        $this->actingAs($this->platformOwner, 'platform')
            ->patch($this->environmentUpdateUrl(), [
                'name' => 'Production Runtime',
                'branch' => 'release',
                'minimum_replicas' => '1',
                'maximum_replicas' => '1',
                'hibernate_after_minutes' => '',
                'post_deployment_observation_minutes' => '',
            ])
            ->assertRedirect($this->environmentUrl());

        $updatedEnvironment = $this->nativeEnvironment->fresh();
        $this->assertSame('Production Runtime', $updatedEnvironment->name);
        $this->assertSame('release', $updatedEnvironment->branch);
        $this->assertSame('echo CONFIGURATION_COMMAND_SENTINEL', $updatedEnvironment->build_command);
        $this->assertSame('php artisan serve --token CONFIGURATION_SECRET_SENTINEL', $updatedEnvironment->start_command);
        $this->assertNull($updatedEnvironment->hibernate_after_minutes);
    }

    public function test_directory_paginates_mapped_projects_and_reaches_a_project_on_the_second_page(): void
    {
        $laterCoreProject = null;
        for ($index = 1; $index <= 20; $index++) {
            $nativeProject = $this->organization->projects()->create([
                'name' => "Mapped project {$index}",
                'slug' => "mapped-project-{$index}",
                'created_by' => $this->nativeOwner->getKey(),
            ]);
            $coreProject = CoreProject::query()->create([
                'workspace_id' => $this->workspace->getKey(),
                'created_by_user_id' => $this->platformOwner->getKey(),
                'name' => "Mapped project {$index}",
                'slug' => "mapped-project-{$index}",
                'status' => 'active',
            ]);
            ProjectMembership::query()->create([
                'project_id' => $coreProject->getKey(), 'user_id' => $this->platformOwner->getKey(),
                'role' => 'admin', 'status' => 'active',
            ]);
            ProjectProduct::query()->create([
                'project_id' => $coreProject->getKey(), 'product' => 'deployer', 'status' => 'active',
            ]);
            $this->mapProject($coreProject, $nativeProject);
            if ($index === 9) {
                $laterCoreProject = $coreProject;
            }
        }

        $this->assertInstanceOf(CoreProject::class, $laterCoreProject);

        $this->actingAs($this->platformOwner, 'platform')
            ->get(route('core.workspace.deployer.configuration.index', [
                'workspace' => $this->workspace,
                'page' => 2,
            ]))
            ->assertOk()
            ->assertSee('Mapped project 9')
            ->assertSee(route('core.workspace.deployer.configuration.projects.show', [$this->workspace, $laterCoreProject]))
            ->assertSee('Previous page');
    }

    public function test_native_plan_entitlements_are_rechecked_for_preview_and_scaling_changes(): void
    {
        config(['billing.enforce_entitlements' => true, 'billing.plan_authority' => 'legacy']);

        $this->actingAs($this->platformOwner, 'platform')
            ->from($this->projectUrl())
            ->patch($this->previewUpdateUrl(), [
                'preview_enabled' => '1',
                'preview_domain' => 'previews.example.test',
                'preview_ttl_hours' => '72',
            ])
            ->assertRedirect($this->projectUrl())
            ->assertSessionHasErrors('plan');
        $this->assertFalse((bool) $this->nativeProject->fresh()->preview_enabled);

        $this->actingAs($this->platformOwner, 'platform')
            ->from($this->environmentUrl())
            ->patch($this->environmentUpdateUrl(), [
                'name' => 'Production',
                'branch' => 'main',
                'minimum_replicas' => '1',
                'maximum_replicas' => '2',
                'hibernate_after_minutes' => '',
                'post_deployment_observation_minutes' => '',
            ])
            ->assertRedirect($this->environmentUrl())
            ->assertSessionHasErrors('plan');
        $this->assertSame(1, $this->nativeEnvironment->fresh()->maximum_replicas);
    }

    public function test_foreign_native_project_mapping_is_rejected_for_configuration(): void
    {
        $foreignOwner = DeployerUser::factory()->create();
        $foreignProject = $foreignOwner->currentOrganization->projects()->create([
            'name' => 'Foreign app', 'slug' => 'foreign-app', 'created_by' => $foreignOwner->getKey(),
        ]);
        ProjectResource::query()
            ->where('project_id', $this->project->getKey())
            ->where('resource_type', 'project')
            ->update(['resource_id' => (string) $foreignProject->getKey()]);

        $this->actingAs($this->platformOwner, 'platform')
            ->get($this->projectUrl())
            ->assertNotFound();
    }

    public function test_forged_environment_type_change_is_rejected_without_mutating_the_native_record(): void
    {
        $this->actingAs($this->platformOwner, 'platform')
            ->from($this->environmentUrl())
            ->patch($this->environmentUpdateUrl(), [
                'name' => 'Production', 'type' => 'staging', 'branch' => 'main',
                'minimum_replicas' => 1, 'maximum_replicas' => 1,
                'hibernate_after_minutes' => '', 'post_deployment_observation_minutes' => '',
            ])
            ->assertRedirect($this->environmentUrl())
            ->assertSessionHasErrors('type');

        $this->assertSame('production', $this->nativeEnvironment->fresh()->type);
        $this->assertSame('main', $this->nativeEnvironment->fresh()->branch);
    }

    public function test_preexisting_core_native_environment_type_mismatch_is_hidden_and_cannot_be_edited(): void
    {
        $this->environment->forceFill(['environment_type' => 'custom'])->save();

        $this->actingAs($this->platformOwner, 'platform')
            ->get($this->environmentUrl())
            ->assertNotFound();
        $this->actingAs($this->platformOwner, 'platform')
            ->patch($this->environmentUpdateUrl(), [
                'name' => 'Would be changed', 'branch' => 'release',
                'minimum_replicas' => 1, 'maximum_replicas' => 1,
                'hibernate_after_minutes' => '', 'post_deployment_observation_minutes' => '',
            ])
            ->assertNotFound();

        $this->assertSame('Production', $this->nativeEnvironment->fresh()->name);
        $this->assertSame('production', $this->nativeEnvironment->fresh()->type);
    }

    public function test_native_role_and_core_product_grant_are_rechecked_for_configuration_access(): void
    {
        $platformDeveloper = PlatformUser::query()->create([
            'name' => 'Workspace developer', 'email' => 'configuration-developer@example.test',
            'email_normalized' => 'configuration-developer@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $platformDeveloper->getKey(),
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        $developerGrant = WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(), 'product' => 'deployer', 'role' => 'member', 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $this->project->getKey(), 'user_id' => $platformDeveloper->getKey(),
            'role' => 'member', 'status' => 'active',
        ]);
        $nativeDeveloper = DeployerUser::factory()->create();
        $this->organization->members()->attach($nativeDeveloper->getKey(), ['role' => 'developer']);
        $this->map('user', $nativeDeveloper->getKey(), 'user', $platformDeveloper->getKey());
        $this->nativeEnvironment->update(['is_protected' => true]);

        $this->actingAs($platformDeveloper, 'platform')
            ->get($this->environmentUrl())
            ->assertForbidden();

        $developerGrant->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->actingAs($platformDeveloper, 'platform')
            ->get($this->projectUrl())
            ->assertNotFound();
    }

    public function test_deletion_fence_blocks_reads_and_updates_until_native_deletion_work_finishes(): void
    {
        ProductDeletionFence::query()->create([
            'kind' => 'account', 'source_id' => (string) $this->nativeOwner->getKey(),
            'request_id' => 'request-configuration-test', 'payload_hash' => str_repeat('a', 64),
            'generation' => 1, 'state' => 'prepared',
        ]);

        $this->actingAs($this->platformOwner, 'platform')
            ->get($this->projectUrl())
            ->assertStatus(410);
        $this->actingAs($this->platformOwner, 'platform')
            ->patch($this->previewUpdateUrl(), [
                'preview_enabled' => '0', 'preview_domain' => 'previews.example.test', 'preview_ttl_hours' => '72',
            ])
            ->assertStatus(410);
        $this->assertFalse((bool) $this->nativeProject->fresh()->preview_enabled);
    }

    private function indexUrl(): string
    {
        return route('core.workspace.deployer.configuration.index', $this->workspace);
    }

    private function projectUrl(): string
    {
        return route('core.workspace.deployer.configuration.projects.show', [$this->workspace, $this->project]);
    }

    private function previewUpdateUrl(): string
    {
        return route('core.workspace.deployer.configuration.projects.previews.update', [$this->workspace, $this->project]);
    }

    private function environmentUrl(): string
    {
        return route('core.workspace.deployer.configuration.projects.environments.show', [$this->workspace, $this->project, $this->environment]);
    }

    private function environmentUpdateUrl(): string
    {
        return route('core.workspace.deployer.configuration.projects.environments.update', [$this->workspace, $this->project, $this->environment]);
    }

    private function mapProject(CoreProject $coreProject, DeployerProject $nativeProject): void
    {
        ProjectResource::query()->create([
            'project_id' => $coreProject->getKey(), 'product' => 'deployer', 'resource_type' => 'project',
            'resource_id' => (string) $nativeProject->getKey(), 'status' => 'active',
        ]);
    }

    private function map(string $sourceEntity, string|int $sourceId, string $canonicalEntity, string $canonicalId): void
    {
        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer', 'source_entity' => $sourceEntity, 'source_id' => (string) $sourceId,
            'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
        ]);
    }
}
