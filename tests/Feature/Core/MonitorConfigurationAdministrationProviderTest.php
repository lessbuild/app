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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    private string $coreDatabasePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->coreDatabasePath = sys_get_temp_dir().'/buildpusher-monitor-core-'.Str::uuid().'.sqlite';
        $this->assertTrue(touch($this->coreDatabasePath));
        config([
            'database.connections.core.database' => $this->coreDatabasePath,
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

    protected function tearDown(): void
    {
        if (config('database.connections.core_concurrent') !== null) {
            DB::purge('core_concurrent');
        }
        DB::purge('core');
        if ($this->coreDatabasePath !== '') {
            foreach ([$this->coreDatabasePath, $this->coreDatabasePath.'-wal', $this->coreDatabasePath.'-shm'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        parent::tearDown();
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

    public function test_check_archive_uses_native_archive_with_role_and_stale_version_fencing(): void
    {
        $provider = app(MonitorConfigurationAdministrationProvider::class);
        $reference = app(MonitorAdministrationContext::class)
            ->reference('monitor', $this->check->getKey(), $this->monitorWorkspace);
        $ownerSnapshot = $provider->snapshot($this->actor, $this->workspace);
        $this->assertTrue($ownerSnapshot->checks->items()[0]['can_archive']);
        $this->assertSame(route('monitor.applications.show', $this->application->getKey()), $ownerSnapshot->applications->items()[0]['archive_url']);

        try {
            $provider->archiveMonitor($this->actor, $this->workspace, $reference, 7);
            $this->fail('A stale archive request must not archive a newer check configuration.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertFalse($this->check->fresh()->trashed());

        $this->monitorWorkspace->members()->updateExistingPivot($this->monitorActor->getKey(), ['role' => 'viewer']);
        $this->assertFalse($provider->snapshot($this->actor, $this->workspace)->checks->items()[0]['can_archive']);
        try {
            $provider->archiveMonitor($this->actor, $this->workspace, $reference, 0);
            $this->fail('The native read-only role must not archive checks.');
        } catch (AuthorizationException) {
            $this->assertFalse($this->check->fresh()->trashed());
        }

        $this->monitorWorkspace->members()->updateExistingPivot($this->monitorActor->getKey(), ['role' => 'owner']);
        $result = $provider->archiveMonitor($this->actor, $this->workspace, $reference, 0);

        $this->assertSame('check_archived', $result->status);
        $archived = Monitor::withTrashed()->findOrFail($this->check->getKey());
        $this->assertTrue($archived->trashed());
        $this->assertFalse($archived->enabled);
        $this->assertNull($archived->next_check_at);
    }

    public function test_core_creates_a_bounded_http_check_only_for_the_exact_active_environment_mapping(): void
    {
        $environmentReference = app(MonitorAdministrationContext::class)
            ->reference('environment', $this->environment->getKey(), $this->monitorWorkspace);

        $result = app(MonitorConfigurationAdministrationProvider::class)->createHttpCheck(
            $this->actor,
            $this->workspace,
            $environmentReference,
            [
                'name' => 'Public readiness', 'request_url' => 'https://health.example.test/ready',
                'interval_minutes' => 5, 'timeout_seconds' => 6,
            ],
        );

        $created = Monitor::query()->where('name', 'Public readiness')->sole();
        $this->assertTrue($result->succeeded);
        $this->assertSame('check_created', $result->status);
        $this->assertSame((int) $this->environment->getKey(), $created->environment_id);
        $this->assertSame('http', $created->type);
        $this->assertSame('https://health.example.test/ready', $created->request_url);
        $this->assertSame('GET', $created->method);
        $this->assertSame(200, $created->status_min);
        $this->assertSame(299, $created->status_max);
        $this->assertSame(5, $created->interval_minutes);
        $this->assertSame(6, $created->timeout_seconds);
        $this->assertSame(2, $created->trigger_checks);
        $this->assertSame(2, $created->recovery_checks);
        $this->assertTrue($created->enabled);

        $snapshot = app(MonitorConfigurationAdministrationProvider::class)->snapshot($this->actor, $this->workspace);
        $serialized = json_encode($snapshot?->checks->items(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Public readiness', $serialized);
        $this->assertStringNotContainsString('health.example.test', $serialized);
        $this->assertStringNotContainsString('request_url', $serialized);
    }

    public function test_check_creation_fails_closed_when_core_environment_mapping_is_ambiguous(): void
    {
        $canonicalEnvironment = ProjectEnvironment::query()->where('project_id', $this->project->getKey())->firstOrFail();
        $mapping = ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'environment')
            ->where('resource_id', (string) $this->environment->getKey())->firstOrFail();
        // Simulate corrupted duplicate source mappings that the database's normal unique index prevents.
        DB::connection('core')->statement('DROP INDEX project_resources_product_resource_type_resource_id_unique');
        ProjectResource::query()->forceCreate([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(),
            'environment_id' => $canonicalEnvironment->getKey(), 'product' => 'monitor', 'resource_type' => 'environment',
            'resource_id' => (string) $this->environment->getKey(), 'name' => $mapping->name, 'status' => 'active', 'mapped_at' => now(),
        ]);
        $environmentReference = app(MonitorAdministrationContext::class)
            ->reference('environment', $this->environment->getKey(), $this->monitorWorkspace);

        try {
            app(MonitorConfigurationAdministrationProvider::class)->createHttpCheck($this->actor, $this->workspace, $environmentReference, [
                'name' => 'Ambiguous mapping check', 'request_url' => 'https://health.example.test/ready',
                'interval_minutes' => 5, 'timeout_seconds' => 5,
            ]);
            $this->fail('A duplicated native-to-Core environment mapping must not receive a new check.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame(1, Monitor::query()->count());
    }

    public function test_check_creation_rejects_a_paused_core_environment_mapping(): void
    {
        ProjectEnvironment::query()->where('project_id', $this->project->getKey())->update(['status' => 'paused']);
        $environmentReference = app(MonitorAdministrationContext::class)
            ->reference('environment', $this->environment->getKey(), $this->monitorWorkspace);

        try {
            app(MonitorConfigurationAdministrationProvider::class)->createHttpCheck($this->actor, $this->workspace, $environmentReference, [
                'name' => 'Paused mapping check', 'request_url' => 'https://health.example.test/ready',
                'interval_minutes' => 5, 'timeout_seconds' => 5,
            ]);
            $this->fail('An active native environment cannot bypass a paused canonical Core environment.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame(1, Monitor::query()->count());
    }

    public function test_check_creation_is_hidden_when_either_core_environment_binding_is_paused(): void
    {
        $provider = app(MonitorConfigurationAdministrationProvider::class);
        $environmentResource = ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'environment')
            ->where('resource_id', (string) $this->environment->getKey())->firstOrFail();
        $canonicalEnvironment = ProjectEnvironment::query()->where('project_id', $this->project->getKey())->firstOrFail();

        $active = $provider->snapshot($this->actor, $this->workspace);
        $this->assertTrue($active?->environments->items()[0]['can_create_check']);

        $environmentResource->update(['status' => 'paused']);
        $pausedResource = $provider->snapshot($this->actor, $this->workspace);
        $this->assertFalse($pausedResource?->environments->items()[0]['can_create_check']);

        $environmentResource->update(['status' => 'active']);
        $canonicalEnvironment->update(['status' => 'paused']);
        $pausedCanonical = $provider->snapshot($this->actor, $this->workspace);
        $this->assertFalse($pausedCanonical?->environments->items()[0]['can_create_check']);
    }

    public function test_check_creation_rechecks_current_core_project_access_after_reference_issue(): void
    {
        $environmentReference = app(MonitorAdministrationContext::class)
            ->reference('environment', $this->environment->getKey(), $this->monitorWorkspace);
        ProjectMembership::query()->where('project_id', $this->project->getKey())->where('user_id', $this->actor->getKey())
            ->update(['status' => 'revoked', 'revoked_at' => now()]);

        try {
            app(MonitorConfigurationAdministrationProvider::class)->createHttpCheck($this->actor, $this->workspace, $environmentReference, [
                'name' => 'Revoked project access check', 'request_url' => 'https://health.example.test/ready',
                'interval_minutes' => 5, 'timeout_seconds' => 5,
            ]);
            $this->fail('A Core project membership revoked after reference issuance must block the native write.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame(1, Monitor::query()->count());
    }

    public function test_check_creation_rechecks_current_project_archive_state_before_native_write(): void
    {
        $environmentReference = app(MonitorAdministrationContext::class)
            ->reference('environment', $this->environment->getKey(), $this->monitorWorkspace);
        $this->project->update(['status' => 'archived', 'archived_at' => now()]);

        try {
            app(MonitorConfigurationAdministrationProvider::class)->createHttpCheck($this->actor, $this->workspace, $environmentReference, [
                'name' => 'Archived project check', 'request_url' => 'https://health.example.test/ready',
                'interval_minutes' => 5, 'timeout_seconds' => 5,
            ]);
            $this->fail('A project archived after reference issuance must block the native write.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame(1, Monitor::query()->count());
    }

    public function test_sqlite_wal_check_creation_fails_closed_when_core_authority_changes_after_snapshot(): void
    {
        $mode = DB::connection('core')->selectOne('PRAGMA journal_mode = WAL');
        $this->assertSame('wal', strtolower((string) ($mode->journal_mode ?? '')));
        config(['database.connections.core_concurrent' => config('database.connections.core')]);
        DB::purge('core_concurrent');
        $concurrent = DB::connection('core_concurrent');
        $environmentReference = app(MonitorAdministrationContext::class)
            ->reference('environment', $this->environment->getKey(), $this->monitorWorkspace);
        $interleaveRevocation = true;
        $revocationCommitted = false;

        DB::listen(function (QueryExecuted $query) use ($concurrent, &$interleaveRevocation, &$revocationCommitted): void {
            if (! $interleaveRevocation || $revocationCommitted || $query->connectionName !== 'monitor'
                || ! str_contains(strtolower($query->sql), 'environments')
                || DB::connection('core')->transactionLevel() === 0) {
                return;
            }

            $revocationCommitted = $concurrent->table('project_memberships')
                ->where('project_id', $this->project->getKey())->where('user_id', $this->actor->getKey())
                ->update(['status' => 'revoked', 'revoked_at' => now()]) === 1;
        });

        try {
            app(MonitorConfigurationAdministrationProvider::class)->createHttpCheck($this->actor, $this->workspace, $environmentReference, [
                'name' => 'Stale SQLite authority check', 'request_url' => 'https://health.example.test/ready',
                'interval_minutes' => 5, 'timeout_seconds' => 5,
            ]);
            $this->fail('A WAL transaction with a stale Core snapshot must not promote to the writer or create a check.');
        } catch (QueryException) {
            $this->assertTrue($revocationCommitted, 'The concurrent Core revocation must commit between the snapshot read and writer reservation.');
        } finally {
            $interleaveRevocation = false;
            DB::purge('core_concurrent');
        }

        $this->assertSame('revoked', ProjectMembership::query()->where('project_id', $this->project->getKey())
            ->where('user_id', $this->actor->getKey())->value('status'));
        $this->assertSame(1, Monitor::query()->count());
    }

    public function test_check_creation_does_not_flash_probe_target_on_validation_redirects(): void
    {
        $environmentReference = app(MonitorAdministrationContext::class)
            ->reference('environment', $this->environment->getKey(), $this->monitorWorkspace);
        $target = 'https://health.example.test/ready?token=do-not-flash-monitor-target';

        $this->actingAs($this->actor, 'platform')->post(route('core.workspace.monitor.configuration.checks.create', $this->workspace), [
            'environment_reference' => $environmentReference, 'name' => 'Invalid target', 'request_url' => $target,
            'interval_minutes' => 5, 'timeout_seconds' => 5,
        ])->assertRedirect()->assertSessionHasErrors('request_url');
        $oldInput = session()->get('_old_input', []);
        $this->assertArrayNotHasKey('request_url', $oldInput);
        $this->assertStringNotContainsString('do-not-flash-monitor-target', json_encode(session()->all(), JSON_THROW_ON_ERROR));

        $this->post(route('core.workspace.monitor.configuration.checks.create', $this->workspace), [
            'environment_reference' => $environmentReference, 'name' => 'Invalid cadence',
            'request_url' => 'https://health.example.test/ready/do-not-flash-cadence-target',
            'interval_minutes' => 2, 'timeout_seconds' => 5,
        ])->assertRedirect()->assertSessionHasErrors('interval_minutes');
        $oldInput = session()->get('_old_input', []);
        $this->assertArrayNotHasKey('request_url', $oldInput);
        $this->assertStringNotContainsString('do-not-flash-cadence-target', json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertSame(1, Monitor::query()->count());
    }

    public function test_check_creation_rechecks_native_manager_role_and_rejects_unsafe_targets_and_cadence(): void
    {
        $provider = app(MonitorConfigurationAdministrationProvider::class);
        $environmentReference = app(MonitorAdministrationContext::class)
            ->reference('environment', $this->environment->getKey(), $this->monitorWorkspace);
        $this->monitorWorkspace->members()->updateExistingPivot($this->monitorActor->getKey(), ['role' => 'member']);

        try {
            $provider->createHttpCheck($this->actor, $this->workspace, $environmentReference, [
                'name' => 'Unauthorized check', 'request_url' => 'https://health.example.test/ready',
                'interval_minutes' => 5, 'timeout_seconds' => 5,
            ]);
            $this->fail('A native Monitor contributor cannot create checks.');
        } catch (AuthorizationException) {
            $this->assertSame(1, Monitor::query()->count());
        }

        $this->monitorWorkspace->members()->updateExistingPivot($this->monitorActor->getKey(), ['role' => 'owner']);
        foreach ([
            ['request_url' => 'https://health.example.test/ready?token=secret', 'interval_minutes' => 5],
            ['request_url' => 'https://health.example.test/ready', 'interval_minutes' => 2],
        ] as $invalid) {
            try {
                $provider->createHttpCheck($this->actor, $this->workspace, $environmentReference, [
                    'name' => 'Invalid check', 'request_url' => $invalid['request_url'],
                    'interval_minutes' => $invalid['interval_minutes'], 'timeout_seconds' => 5,
                ]);
                $this->fail('Unsafe targets and unsupported cadence must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertSame(1, Monitor::query()->count());
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
        $this->assertNull($snapshot->applications->items()[0]['archive_url']);
        $this->assertNull($snapshot->environments->items()[0]['archive_url']);

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
