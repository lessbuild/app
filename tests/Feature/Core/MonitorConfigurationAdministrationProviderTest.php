<?php

namespace Tests\Feature\Core;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\WorkspaceMonitorAdministrationRegistry;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\User as MonitorUser;
use App\Modules\Monitor\Models\Workspace as MonitorWorkspace;
use App\Modules\Monitor\Policies\ApplicationPolicy;
use App\Modules\Monitor\Policies\EnvironmentPolicy;
use App\Modules\Monitor\Policies\MonitorPolicy;
use App\Modules\Monitor\Policies\WorkspacePolicy;
use App\Modules\Monitor\Services\Core\MonitorAdministrationContext;
use App\Modules\Monitor\Services\Core\MonitorConfigurationAdministrationProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Regression coverage authored for mapped Core Monitor configuration; intentionally not executed here. */
final class MonitorConfigurationAdministrationProviderTest extends TestCase
{
    private PlatformUser $actor;

    private CoreWorkspace $workspace;

    private Project $project;

    private MonitorUser $monitorActor;

    private MonitorWorkspace $monitorWorkspace;

    private Application $application;

    private Environment $environment;

    private Monitor $check;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.core.database' => ':memory:',
            'database.connections.monitor.database' => ':memory:',
            'platform.products.monitor.enabled' => true,
            'platform.products.monitor.auth_authority' => 'core',
            'billing.enforce_entitlements' => false,
        ]);
        foreach (['core', 'monitor'] as $connection) {
            DB::purge($connection);
            $this->assertSame(0, Artisan::call('platform:migrate', ['module' => $connection]));
        }

        Gate::policy(MonitorWorkspace::class, WorkspacePolicy::class);
        Gate::policy(Application::class, ApplicationPolicy::class);
        Gate::policy(Environment::class, EnvironmentPolicy::class);
        Gate::policy(Monitor::class, MonitorPolicy::class);

        $this->actor = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Core owner', 'email' => 'monitor-config-owner@example.test',
            'email_normalized' => 'monitor-config-owner@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->workspace = CoreWorkspace::query()->forceCreate([
            'id' => (string) Str::ulid(), 'owner_user_id' => $this->actor->getKey(),
            'name' => 'Core workspace', 'slug' => 'monitor-configuration', 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->forceCreate([
            'id' => (string) Str::ulid(), 'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->actor->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        WorkspaceProductAccess::query()->forceCreate([
            'id' => (string) Str::ulid(), 'membership_id' => $membership->getKey(), 'product' => 'monitor',
            'role' => 'owner', 'status' => 'active', 'granted_at' => now(),
        ]);
        $this->project = Project::query()->forceCreate([
            'id' => (string) Str::ulid(), 'workspace_id' => $this->workspace->getKey(), 'created_by_user_id' => $this->actor->getKey(),
            'name' => 'Mapped service', 'slug' => 'mapped-service', 'status' => 'active',
        ]);
        ProjectMembership::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'user_id' => $this->actor->getKey(),
            'role' => 'owner', 'status' => 'active', 'granted_at' => now(),
        ]);
        ProjectProduct::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'product' => 'monitor', 'status' => 'active',
        ]);

        $this->monitorActor = MonitorUser::query()->forceCreate([
            'name' => 'Monitor owner', 'email' => 'native-monitor-config@example.test', 'password' => 'hashed', 'email_verified_at' => now(),
        ]);
        $this->monitorWorkspace = MonitorWorkspace::query()->forceCreate([
            'owner_id' => $this->monitorActor->getKey(), 'name' => 'Monitor workspace', 'slug' => 'monitor-config-source', 'plan' => 'free',
        ]);
        $this->monitorWorkspace->members()->attach($this->monitorActor, ['role' => 'owner']);
        $this->application = Application::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'name' => 'Public API', 'slug' => 'public-api',
            'framework' => 'Laravel', 'framework_version' => '12', 'accent' => 'violet',
        ]);
        $this->environment = Environment::query()->forceCreate([
            'application_id' => $this->application->getKey(), 'name' => 'Production', 'slug' => 'production', 'status' => 'active',
        ]);
        $this->check = Monitor::factory()->create([
            'environment_id' => $this->environment->getKey(), 'name' => 'Public health',
            'request_url' => 'https://health.example.test/internal?credential=do-not-expose', 'enabled' => true,
        ]);

        $this->map('user', (string) $this->monitorActor->getKey(), 'user', (string) $this->actor->getKey());
        $this->map('workspace', (string) $this->monitorWorkspace->getKey(), 'workspace', (string) $this->workspace->getKey());
        $this->map('application', (string) $this->application->getKey(), 'project', (string) $this->project->getKey());
        $canonicalEnvironment = ProjectEnvironment::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'created_by_user_id' => $this->actor->getKey(),
            'name' => 'Production', 'slug' => 'production', 'environment_type' => 'production', 'status' => 'active',
        ]);
        $this->map('environment', (string) $this->environment->getKey(), 'project_environment', (string) $canonicalEnvironment->getKey());
        ProjectResource::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'product' => 'monitor', 'resource_type' => 'application',
            'resource_id' => (string) $this->application->getKey(), 'name' => 'Public API', 'status' => 'active', 'mapped_at' => now(),
        ]);
        ProjectResource::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'environment_id' => $canonicalEnvironment->getKey(),
            'product' => 'monitor', 'resource_type' => 'environment', 'resource_id' => (string) $this->environment->getKey(),
            'name' => 'Production', 'status' => 'active', 'mapped_at' => now(),
        ]);
        app(WorkspaceMonitorAdministrationRegistry::class)->registerConfiguration(app(MonitorConfigurationAdministrationProvider::class));
    }

    public function test_snapshot_lists_only_mapped_resources_and_omits_probe_targets_and_credentials(): void
    {
        $unmappedApplication = Application::query()->forceCreate([
            'workspace_id' => $this->monitorWorkspace->getKey(), 'name' => 'Unmapped service', 'slug' => 'unmapped-service', 'framework' => 'Node.js',
        ]);
        $unmappedEnvironment = Environment::query()->forceCreate([
            'application_id' => $unmappedApplication->getKey(), 'name' => 'Preview', 'slug' => 'preview', 'status' => 'active',
        ]);
        Monitor::factory()->create(['environment_id' => $unmappedEnvironment->getKey(), 'name' => 'Unmapped check']);
        $canonicalEnvironment = ProjectEnvironment::query()->where('project_id', $this->project->getKey())->firstOrFail();
        ProjectResource::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'product' => 'monitor', 'resource_type' => 'application',
            'resource_id' => 'stale-native-application', 'name' => 'Stale app mapping', 'status' => 'active', 'mapped_at' => now(),
        ]);
        ProjectResource::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'environment_id' => $canonicalEnvironment->getKey(),
            'product' => 'monitor', 'resource_type' => 'environment', 'resource_id' => 'stale-native-environment',
            'name' => 'Stale environment mapping', 'status' => 'active', 'mapped_at' => now(),
        ]);

        $snapshot = app(MonitorConfigurationAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $serialized = json_encode([
            $snapshot?->applications->items(), $snapshot?->environments->items(), $snapshot?->checks->items(),
        ], JSON_THROW_ON_ERROR);

        $this->assertNotNull($snapshot);
        $this->assertSame(1, $snapshot->applications->total());
        $this->assertSame(1, $snapshot->environments->total());
        $this->assertSame(1, $snapshot->checks->total());
        $this->assertSame('Public API', $snapshot->applications->items()[0]['name']);
        $this->assertSame('Public health', $snapshot->checks->items()[0]['name']);
        $this->assertStringNotContainsString('Unmapped service', $serialized);
        $this->assertStringNotContainsString('health.example.test', $serialized);
        $this->assertStringNotContainsString('do-not-expose', $serialized);
        $this->assertArrayNotHasKey('id', $snapshot->checks->items()[0]);
    }

    public function test_check_search_reaches_mapped_environments_beyond_the_environment_page(): void
    {
        $targetEnvironment = null;
        for ($index = 1; $index <= 21; $index++) {
            $name = sprintf('Mapped environment %02d', $index);
            $slug = sprintf('mapped-%02d', $index);
            $environment = Environment::query()->forceCreate([
                'application_id' => $this->application->getKey(), 'name' => $name, 'slug' => $slug, 'status' => 'active',
            ]);
            $canonicalEnvironment = ProjectEnvironment::query()->forceCreate([
                'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'created_by_user_id' => $this->actor->getKey(),
                'name' => $name, 'slug' => $slug, 'environment_type' => 'production', 'status' => 'active',
            ]);
            $this->map('environment', (string) $environment->getKey(), 'project_environment', (string) $canonicalEnvironment->getKey());
            ProjectResource::query()->forceCreate([
                'id' => $index === 21 ? '7'.str_repeat('Z', 25) : (string) Str::ulid(),
                'project_id' => $this->project->getKey(), 'environment_id' => $canonicalEnvironment->getKey(),
                'product' => 'monitor', 'resource_type' => 'environment', 'resource_id' => (string) $environment->getKey(),
                'name' => $name, 'status' => 'active', 'mapped_at' => now(),
            ]);

            if ($index === 21) {
                $targetEnvironment = $environment;
                Monitor::factory()->create([
                    'environment_id' => $environment->getKey(), 'name' => 'Reachable beyond environment page',
                ]);
            } else {
                Monitor::factory()->create([
                    'environment_id' => $environment->getKey(), 'name' => sprintf('Mapped check %02d', $index),
                ]);
            }
        }

        $this->assertNotNull($targetEnvironment);
        $snapshot = app(MonitorConfigurationAdministrationProvider::class)->snapshot($this->actor, $this->workspace, [
            'check_search' => 'Reachable beyond environment page',
        ]);

        $this->assertNotNull($snapshot);
        $this->assertSame(22, $snapshot->environments->total());
        $this->assertCount(20, $snapshot->environments->items());
        $this->assertNotContains('Mapped environment 21', array_column($snapshot->environments->items(), 'name'));
        $this->assertSame(1, $snapshot->checks->total());
        $this->assertSame('Reachable beyond environment page', $snapshot->checks->items()[0]['name']);
        $this->assertSame('Mapped environment 21', $snapshot->checks->items()[0]['environment']);

        $secondCheckPage = app(MonitorConfigurationAdministrationProvider::class)->snapshot($this->actor, $this->workspace, [
            'checks_page' => 2,
        ]);
        $this->assertNotNull($secondCheckPage);
        $this->assertSame(22, $secondCheckPage->checks->total());
        $this->assertSame(2, $secondCheckPage->checks->currentPage());
        $this->assertCount(2, $secondCheckPage->checks->items());
        $this->assertContains('Reachable beyond environment page', array_column($secondCheckPage->checks->items(), 'name'));
        $this->assertSame('Mapped environment 21', $secondCheckPage->checks->items()[1]['environment']);
    }

    public function test_application_and_environment_updates_use_native_authority_and_pause_signal_checks(): void
    {
        $provider = app(MonitorConfigurationAdministrationProvider::class);
        $context = app(MonitorAdministrationContext::class);
        $applicationReference = $context->reference('application', $this->application->getKey(), $this->monitorWorkspace);
        $environmentReference = $context->reference('environment', $this->environment->getKey(), $this->monitorWorkspace);
        $heartbeat = Monitor::factory()->heartbeat()->create(['environment_id' => $this->environment->getKey(), 'enabled' => true]);
        $queue = Monitor::factory()->queueMonitor()->create(['environment_id' => $this->environment->getKey(), 'enabled' => true]);

        $provider->updateApplication($this->actor, $this->workspace, $applicationReference, [
            'name' => 'Customer API', 'framework' => 'Laravel', 'framework_version' => '13', 'accent' => 'sky',
        ]);
        $provider->updateEnvironment($this->actor, $this->workspace, $environmentReference, [
            'name' => 'Production EU', 'slug' => 'production-eu', 'status' => 'paused',
        ]);

        $this->assertSame('Customer API', $this->application->fresh()->name);
        $this->assertSame('production-eu', $this->environment->fresh()->slug);
        $this->assertSame('paused', $this->environment->fresh()->status);
        $this->assertSame(1, $this->application->fresh()->lifecycle_revision);
        $this->assertFalse($heartbeat->fresh()->enabled);
        $this->assertFalse($queue->fresh()->enabled);
    }

    public function test_check_update_reuses_native_action_for_cadence_state_and_stale_version_fencing(): void
    {
        $provider = app(MonitorConfigurationAdministrationProvider::class);
        $reference = app(MonitorAdministrationContext::class)
            ->reference('monitor', $this->check->getKey(), $this->monitorWorkspace);

        $provider->updateMonitor($this->actor, $this->workspace, $reference, [
            'name' => 'Public endpoint', 'enabled' => false, 'version' => 0,
            'interval_minutes' => 15, 'timeout_seconds' => 6, 'trigger_checks' => 3, 'recovery_checks' => 2,
        ]);

        $updated = $this->check->fresh();
        $this->assertSame('Public endpoint', $updated->name);
        $this->assertFalse($updated->enabled);
        $this->assertSame(15, $updated->interval_minutes);
        $this->assertSame(6, $updated->timeout_seconds);
        $this->assertSame(1, $updated->state_version);
        $this->assertSame(1, $updated->config_revision);

        try {
            $provider->updateMonitor($this->actor, $this->workspace, $reference, [
                'name' => 'Stale update', 'enabled' => true, 'version' => 0, 'interval_minutes' => 5,
            ]);
            $this->fail('A stale check form must not overwrite a newer native configuration.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame('Public endpoint', $this->check->fresh()->name);
        $this->assertFalse($this->check->fresh()->enabled);
    }

    public function test_core_project_membership_is_required_for_snapshot_and_direct_put_even_with_product_grant(): void
    {
        $snapshot = app(MonitorConfigurationAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $knownReference = $snapshot?->applications->items()[0]['reference'];
        $this->assertIsString($knownReference);
        ProjectMembership::query()->where('project_id', $this->project->getKey())->where('user_id', $this->actor->getKey())
            ->update(['status' => 'revoked', 'revoked_at' => now()]);

        $deniedSnapshot = app(MonitorConfigurationAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $this->assertNotNull($deniedSnapshot);
        $this->assertSame(0, $deniedSnapshot->applications->total());
        $this->assertSame(0, $deniedSnapshot->environments->total());
        $this->assertSame(0, $deniedSnapshot->checks->total());

        $response = $this->actingAs($this->actor, 'platform')->put(
            route('core.workspace.monitor.configuration.applications.update', $this->workspace),
            ['application_reference' => $knownReference, 'name' => 'Unauthorized edit', 'framework' => 'Laravel', 'framework_version' => '12', 'accent' => 'violet'],
        );
        $response->assertNotFound();
        $this->assertSame('Public API', $this->application->fresh()->name);
    }

    public function test_read_only_native_role_sees_configuration_without_edit_controls_or_mutation_authority(): void
    {
        $this->monitorWorkspace->members()->updateExistingPivot($this->monitorActor->getKey(), ['role' => 'viewer']);
        $snapshot = app(MonitorConfigurationAdministrationProvider::class)->snapshot($this->actor, $this->workspace);

        $this->assertNotNull($snapshot);
        $this->assertSame(1, $snapshot->applications->total());
        $this->assertSame(1, $snapshot->environments->total());
        $this->assertSame(1, $snapshot->checks->total());
        $this->assertFalse($snapshot->applications->items()[0]['can_update']);
        $this->assertFalse($snapshot->environments->items()[0]['can_update']);
        $this->assertFalse($snapshot->checks->items()[0]['can_update']);

        try {
            app(MonitorConfigurationAdministrationProvider::class)->updateApplication(
                $this->actor,
                $this->workspace,
                $snapshot->applications->items()[0]['reference'],
                ['name' => 'Unauthorized role edit', 'framework' => 'Laravel', 'framework_version' => '12', 'accent' => 'violet'],
            );
            $this->fail('The native read-only role must not change application configuration.');
        } catch (AuthorizationException) {
            $this->assertSame('Public API', $this->application->fresh()->name);
        }
    }

    public function test_archived_resources_and_stale_core_bindings_are_not_editable(): void
    {
        $reference = app(MonitorAdministrationContext::class)
            ->reference('application', $this->application->getKey(), $this->monitorWorkspace);
        ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'application')
            ->where('resource_id', (string) $this->application->getKey())->update(['status' => 'inactive']);

        try {
            app(MonitorConfigurationAdministrationProvider::class)->updateApplication($this->actor, $this->workspace, $reference, [
                'name' => 'Stale mapping edit', 'framework' => 'Laravel', 'framework_version' => '12', 'accent' => 'violet',
            ]);
            $this->fail('Inactive Core mappings must reject configuration writes.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame('Public API', $this->application->fresh()->name);
    }

    private function map(string $sourceEntity, string $sourceId, string $canonicalEntity, string $canonicalId): void
    {
        LegacyIdentityMap::query()->forceCreate([
            'id' => (string) Str::ulid(), 'source_product' => 'monitor', 'source_entity' => $sourceEntity,
            'source_id' => $sourceId, 'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId,
            'status' => 'reconciled',
        ]);
    }
}
