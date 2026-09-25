<?php

namespace Tests\Feature\Monitor;

use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Core\Exceptions\Restoration\ResourceRestorationBlocked;
use App\Core\Exceptions\Restoration\ResourceRestorationSuperseded;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\ResourceRestorationRequest;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Restoration\ProcessResourceRestoration;
use App\Core\Services\Restoration\ProductResourceRestorationRegistry;
use App\Core\Services\Restoration\RequestResourceRestoration;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestToken;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\ResourceRestorationReceipt;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ArchiveApplication;
use App\Modules\Monitor\Services\ArchiveEnvironment;
use App\Modules\Monitor\Services\Core\MonitorResourceRestorationProvider;
use App\Modules\Monitor\Services\Core\RestoreMonitorResource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Authored only: execution remains deferred until the full source plan is complete. */
final class ResourceRestorationTest extends TestCase
{
    private ?string $monitorDatabasePath = null;

    private PlatformUser $actor;

    private User $user;

    private Workspace $workspace;

    private CoreWorkspace $coreWorkspace;

    private Project $project;

    private ProjectProduct $product;

    private WorkspaceProductAccess $grant;

    private Application $application;

    private ProjectResource $resource;

    private MonitorResourceRestorationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['core', 'monitor'] as $connection) {
            $database = ':memory:';
            if ($connection === 'monitor' && $this->name() === 'test_sqlite_wal_source_write_is_fenced_during_the_projection_callback') {
                $this->monitorDatabasePath = tempnam(sys_get_temp_dir(), 'monitor-restoration-');
                $database = $this->monitorDatabasePath;
            }
            config(["database.connections.{$connection}.database" => $database]);
            DB::purge($connection);
            $this->assertSame(0, Artisan::call('platform:migrate', ['module' => $connection]));
        }
        config(['platform.products.monitor.enabled' => true, 'platform.products.monitor.auth_authority' => 'core']);
        foreach (['Workspace', 'Application', 'Environment'] as $model) {
            Gate::policy('App\\Modules\\Monitor\\Models\\'.$model, 'App\\Modules\\Monitor\\Policies\\'.$model.'Policy');
        }
        $this->actor = PlatformUser::query()->create(['name' => 'Owner', 'email' => 'restore@example.test', 'password' => 'unused', 'status' => 'active']);
        $this->user = User::query()->forceCreate(['name' => 'Owner', 'email' => 'native@example.test', 'password' => 'unused', 'email_verified_at' => now()]);
        $this->workspace = Workspace::query()->forceCreate(['owner_id' => $this->user->id, 'name' => 'Monitor', 'slug' => 'monitor', 'plan' => 'free']);
        $this->workspace->members()->attach($this->user, ['role' => 'owner']);
        $this->coreWorkspace = CoreWorkspace::query()->create(['owner_user_id' => $this->actor->id, 'name' => 'Core', 'slug' => 'core', 'status' => 'active']);
        $membership = WorkspaceMembership::query()->create(['workspace_id' => $this->coreWorkspace->id, 'user_id' => $this->actor->id, 'role' => 'owner', 'status' => 'active']);
        $this->grant = WorkspaceProductAccess::query()->create(['membership_id' => $membership->id, 'product' => 'monitor', 'role' => 'owner', 'status' => 'active']);
        $this->identity('user', $this->user->id, 'user', $this->actor->id);
        $this->identity('workspace', $this->workspace->id, 'workspace', $this->coreWorkspace->id);
        $this->project = Project::query()->create(['workspace_id' => $this->coreWorkspace->id, 'name' => 'App', 'slug' => 'app', 'status' => 'active']);
        ProjectMembership::query()->create(['project_id' => $this->project->id, 'user_id' => $this->actor->id, 'role' => 'owner', 'status' => 'active']);
        $this->application = Application::query()->forceCreate(['workspace_id' => $this->workspace->id, 'name' => 'App', 'slug' => 'app', 'accent' => 'violet', 'framework' => 'Laravel']);
        $this->product = ProjectProduct::query()->create(['project_id' => $this->project->id, 'product' => 'monitor', 'status' => 'active']);
        $this->resource = ProjectResource::query()->create(['project_id' => $this->project->id, 'product' => 'monitor', 'resource_type' => 'application', 'resource_id' => (string) $this->application->id, 'status' => 'archived']);
        $this->identity('application', $this->application->id, 'project', $this->project->id);
        $this->provider = app(MonitorResourceRestorationProvider::class);
        app(ProductResourceRestorationRegistry::class)->register($this->provider);
    }

    protected function tearDown(): void
    {
        DB::purge('monitor_contender');
        DB::purge('monitor');
        if ($this->monitorDatabasePath !== null) {
            foreach ([$this->monitorDatabasePath, $this->monitorDatabasePath.'-wal', $this->monitorDatabasePath.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        parent::tearDown();
    }

    public function test_sqlite_wal_source_write_is_fenced_during_the_projection_callback(): void
    {
        DB::connection('monitor')->statement('PRAGMA journal_mode=WAL');
        config(['database.connections.monitor_contender' => config('database.connections.monitor')]);
        DB::connection('monitor_contender')->statement('PRAGMA busy_timeout=0');
        app(ArchiveApplication::class)->archive($this->application);
        $request = $this->claim($this->request());
        $receipt = $this->provider->apply($request->attempt());
        $blocked = false;

        $this->provider->withCurrentReceipt($request->attempt(), function () use (&$blocked): void {
            try {
                DB::connection('monitor_contender')->table('applications')->where('id', $this->application->id)
                    ->update(['deleted_at' => now(), 'lifecycle_revision' => DB::raw('lifecycle_revision + 1')]);
            } catch (QueryException $exception) {
                $this->assertStringContainsString('locked', strtolower($exception->getMessage()));
                $blocked = true;
            }
        });

        $this->assertTrue($blocked, 'The source writer must stay fenced until the projection callback returns.');
        $this->assertFalse($this->application->fresh()->trashed());
        $this->assertSame($receipt->revision, $this->application->fresh()->lifecycle_revision);
    }

    public function test_interrupted_source_commit_recovers_once_with_native_child_states_and_credentials_preserved(): void
    {
        [$active, $activeResource, $activeCanonical] = $this->environment('production');
        [$paused, $pausedResource, $pausedCanonical] = $this->environment('staging', 'paused');
        [$deleted, $deletedResource, $deletedCanonical] = $this->environment('retired');
        app(ArchiveEnvironment::class)->archive($deleted);
        $token = IngestToken::query()->forceCreate(['environment_id' => $active->id, 'created_by' => $this->user->id, 'name' => 'Old collector', 'prefix' => 'bcn_old', 'token_hash' => hash('sha256', 'old')]);
        $heartbeat = Monitor::factory()->heartbeat()->create(['environment_id' => $active->id]);
        $queue = Monitor::factory()->queueMonitor()->create(['environment_id' => $active->id]);
        app(ArchiveApplication::class)->archive($this->application);
        $beforeToken = $token->fresh()->getAttributes();
        $beforeHeartbeat = $heartbeat->fresh()->getAttributes();
        $beforeQueue = $queue->fresh()->getAttributes();
        $request = $this->request();
        $attempt = $this->claim($request)->attempt();

        $receipt = $this->provider->apply($attempt);
        $this->assertFalse($this->application->fresh()->trashed());
        $this->assertSame('archived', $this->resource->fresh()->status);
        $this->assertSame($receipt->hash(), $this->provider->apply($attempt)->hash());
        $this->assertSame(1, ResourceRestorationReceipt::query()->count());
        $this->assertSame($receipt->revision, $this->application->fresh()->lifecycle_revision);

        $request->refresh()->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $processor = app(ProcessResourceRestoration::class);
        $this->assertSame(1, $processor->recoverExpiredLeases());
        $this->assertSame('completed', $processor->process($request->id));
        $this->assertSame(1, ResourceRestorationReceipt::query()->count());
        $this->assertSame('active', $this->resource->fresh()->status);
        $this->assertSame('active', $activeResource->fresh()->status);
        $this->assertSame('active', $activeCanonical->fresh()->status);
        $this->assertSame('paused', $pausedResource->fresh()->status);
        $this->assertSame('paused', $pausedCanonical->fresh()->status);
        $this->assertTrue($deleted->fresh()->trashed());
        $this->assertSame('archived', $deletedResource->fresh()->status);
        $this->assertSame('archived', $deletedCanonical->fresh()->status);
        $this->assertSame($beforeToken, $token->fresh()->getAttributes());
        $this->assertSame($beforeHeartbeat, $heartbeat->fresh()->getAttributes());
        $this->assertSame($beforeQueue, $queue->fresh()->getAttributes());
        $this->assertSame('active', $this->project->fresh()->status);
        $this->assertSame('active', $this->grant->fresh()->status);
    }

    public function test_native_rearchive_invalidates_a_receipt_before_canonical_projection(): void
    {
        $this->environment('production');
        app(ArchiveApplication::class)->archive($this->application);
        $request = $this->claim($this->request());
        $receipt = $this->provider->apply($request->attempt());
        app(ArchiveApplication::class)->archive($this->application->fresh());
        $this->assertGreaterThan($receipt->revision, $this->application->fresh()->lifecycle_revision);
        $committed = false;
        try {
            $this->provider->withCurrentReceipt($request->attempt(), function () use (&$committed): void {
                $committed = true;
            });
            $this->fail('A stale receipt must not project.');
        } catch (ResourceRestorationSuperseded) {
            $this->assertFalse($committed);
            $this->assertSame('archived', $this->resource->fresh()->status);
        }
    }

    public function test_existing_native_receipt_does_not_bypass_revoked_product_authority(): void
    {
        app(ArchiveApplication::class)->archive($this->application);
        $request = $this->claim($this->request());
        $this->provider->apply($request->attempt());
        $this->grant->update(['status' => 'revoked']);

        $this->expectException(ResourceRestorationBlocked::class);
        $this->provider->apply($request->attempt());
    }

    public function test_native_management_role_is_rechecked_on_receipt_replay(): void
    {
        app(ArchiveApplication::class)->archive($this->application);
        $request = $this->claim($this->request());
        $this->provider->apply($request->attempt());
        $this->workspace->members()->updateExistingPivot($this->user->id, ['role' => 'viewer']);

        $this->expectException(ResourceRestorationBlocked::class);
        $this->provider->apply($request->attempt());
    }

    public function test_changed_resource_mapping_blocks_before_source_restoration(): void
    {
        app(ArchiveApplication::class)->archive($this->application);
        $request = $this->claim($this->request());
        $this->resource->update(['metadata' => ['archive_origin' => 'independent']]);
        try {
            $this->provider->apply($request->attempt());
            $this->fail('Changed mapping provenance must block the attempt.');
        } catch (ResourceRestorationBlocked) {
            $this->assertTrue($this->application->fresh()->trashed());
            $this->assertSame(0, ResourceRestorationReceipt::query()->count());
        }
    }

    public function test_telemetry_counters_do_not_supersede_a_native_receipt(): void
    {
        [$environment] = $this->environment('production');
        app(ArchiveApplication::class)->archive($this->application);
        $request = $this->claim($this->request());
        $receipt = $this->provider->apply($request->attempt());
        $environment->update(['event_count' => 100, 'last_seen_at' => now()]);
        $verified = false;
        $this->provider->withCurrentReceipt($request->attempt(), function ($current) use ($receipt, &$verified): void {
            $this->assertSame($receipt->hash(), $current->hash());
            $verified = true;
        });
        $this->assertTrue($verified);
    }

    public function test_independently_archived_canonical_child_stays_archived_when_its_native_parent_returns(): void
    {
        [$environment, $resource, $canonical] = $this->environment('production');
        $resource->update(['metadata' => ['source_application_id' => (string) $this->application->id]]);
        $canonical->update(['metadata' => ['source_application_id' => (string) $this->application->id]]);
        app(ArchiveApplication::class)->archive($this->application);
        $request = $this->request();

        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($request->id));
        $this->assertFalse($environment->fresh()->trashed());
        $this->assertSame('archived', $resource->fresh()->status);
        $this->assertSame('archived', $canonical->fresh()->status);
    }

    public function test_environment_restore_preserves_pause_and_requires_an_active_native_parent(): void
    {
        [$environment, $resource, $canonical] = $this->environment('staging', 'paused');
        $this->resource->update(['status' => 'active']);
        app(ArchiveEnvironment::class)->archive($environment);
        $request = app(RequestResourceRestoration::class)->request($this->actor, $resource, (string) Str::uuid());
        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($request->id));
        $this->assertFalse($environment->fresh()->trashed());
        $this->assertSame('paused', $environment->fresh()->status);
        $this->assertSame('paused', $canonical->fresh()->status);

        app(ArchiveEnvironment::class)->archive($environment->fresh());
        app(ArchiveApplication::class)->archive($this->application);
        $this->expectException(ResourceRestorationBlocked::class);
        app(RequestResourceRestoration::class)->request($this->actor, $resource->fresh(), (string) Str::uuid());
    }

    public function test_archived_core_project_has_a_retained_detail_link_but_cannot_be_implicitly_restored(): void
    {
        app(ArchiveApplication::class)->archive($this->application);
        $this->project->update(['status' => 'archived', 'archived_at' => now()]);
        Route::get('/core/projects', fn () => '')->name('core.projects.index');

        $this->assertTrue(Gate::forUser($this->user)->allows('viewRetained', $this->application->fresh()));
        $this->assertFalse(Gate::forUser($this->user)->allows('view', $this->application->fresh()));
        $this->assertFalse(Gate::forUser($this->user)->allows('restore', $this->application->fresh()));
        $data = app(RestoreMonitorResource::class)->viewData($this->user, $this->application->fresh());
        $this->assertStringContainsString('status=archived', $data['coreProjectRestoreUrl']);
        $this->assertStringContainsString('workspace='.$this->coreWorkspace->id, $data['coreProjectRestoreUrl']);
        $this->expectException(ResourceRestorationBlocked::class);
        $this->request();
    }

    public function test_missing_resource_with_legacy_identity_cannot_take_the_unmapped_restore_path(): void
    {
        app(ArchiveApplication::class)->archive($this->application);
        $this->resource->delete();
        try {
            app(RestoreMonitorResource::class)->restore($this->user, $this->application->fresh(), (string) Str::uuid());
            $this->fail('An identity-only source needs reconciliation.');
        } catch (ValidationException) {
            $this->assertTrue($this->application->fresh()->trashed());
        }
    }

    public function test_true_unmapped_and_legacy_restores_keep_native_role_checks_and_bump_revision(): void
    {
        app(ArchiveApplication::class)->archive($this->application);
        $this->resource->delete();
        LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'application')->delete();
        $revision = $this->application->fresh()->lifecycle_revision;
        $this->assertNull(app(RestoreMonitorResource::class)->restore($this->user, $this->application->fresh(), (string) Str::uuid()));
        $this->assertSame($revision + 1, $this->application->fresh()->lifecycle_revision);
        $this->assertSame(0, ResourceRestorationRequest::query()->count());
        $this->assertSame(0, ResourceRestorationReceipt::query()->count());

        config(['platform.products.monitor.auth_authority' => 'legacy']);
        app(ArchiveApplication::class)->archive($this->application->fresh());
        $this->workspace->members()->updateExistingPivot($this->user->id, ['role' => 'viewer']);
        $this->assertFalse(Gate::forUser($this->user)->allows('restore', $this->application->fresh()));
    }

    public function test_pending_source_restoration_stays_discoverable_in_retained_archive_list(): void
    {
        app(ArchiveApplication::class)->archive($this->application);
        $request = $this->claim($this->request());
        $this->provider->apply($request->attempt());
        Route::get('/restorations/{restoration}', fn () => '')->name('platform.resource-restorations.show');
        $bridge = app(RestoreMonitorResource::class);
        $this->assertContains((string) $this->application->id, $bridge->retainedApplicationIds());
        $this->assertTrue(Application::withTrashed()->visibleTo($this->user, $this->workspace, ProjectResourceAccessPurpose::RetainedRead)->whereKey($this->application->id)->exists());
        $data = $bridge->viewData($this->user, $this->application->fresh());
        $this->assertTrue($data['needsRestoration']);
        $this->assertStringContainsString($request->id, $data['restorationProgressUrl']);
    }

    public function test_same_idempotency_key_reuses_pending_request_without_native_mutation(): void
    {
        $this->environment('production');
        app(ArchiveApplication::class)->archive($this->application);
        $key = (string) Str::uuid();
        $first = app(RequestResourceRestoration::class)->request($this->actor, $this->resource, $key);
        $second = app(RequestResourceRestoration::class)->request($this->actor, $this->resource, $key);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ResourceRestorationRequest::query()->count());
        $this->assertTrue($this->application->fresh()->trashed());
    }

    public function test_duplicate_request_after_interrupted_native_commit_uses_verified_current_receipt(): void
    {
        app(ArchiveApplication::class)->archive($this->application);
        $key = (string) Str::uuid();
        $request = app(RequestResourceRestoration::class)->request($this->actor, $this->resource, $key);
        $this->provider->apply($this->claim($request)->attempt());

        $duplicate = app(RequestResourceRestoration::class)->request($this->actor, $this->resource->fresh(), $key);
        $this->assertSame($request->id, $duplicate->id);
        $this->assertSame(1, ResourceRestorationReceipt::query()->count());
        $this->assertSame(1, ResourceRestorationRequest::query()->count());
    }

    public function test_resource_only_mapping_restores_without_inventing_a_legacy_identity(): void
    {
        LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'application')->delete();
        app(ArchiveApplication::class)->archive($this->application);
        $request = $this->request();

        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($request->id));
        $this->assertSame('active', $this->resource->fresh()->status);
        $this->assertFalse(LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'application')->exists());
    }

    public function test_unmapped_parent_preserves_independent_child_links_but_blocks_orphan_import_provenance(): void
    {
        [$environment, $child, $canonical] = $this->environment('production');
        $this->resource->delete();
        LegacyIdentityMap::query()->where('source_product', 'monitor')->whereIn('source_entity', ['application', 'environment'])->delete();
        app(ArchiveApplication::class)->archive($this->application);
        try {
            app(RestoreMonitorResource::class)->restore($this->user, $this->application->fresh(), (string) Str::uuid());
            $this->fail('An orphaned imported application requires reconciliation.');
        } catch (ValidationException) {
            $this->assertTrue($this->application->fresh()->trashed());
        }
        $child->update(['metadata' => []]);
        $canonical->update(['metadata' => []]);
        $this->assertNull(app(RestoreMonitorResource::class)->restore($this->user, $this->application->fresh(), (string) Str::uuid()));
        $this->assertSame('archived', $child->fresh()->status);
        $this->assertSame('archived', $canonical->fresh()->status);
        $this->assertFalse($environment->fresh()->trashed());
    }

    public function test_environment_restore_rejects_a_parent_mapped_to_a_different_project(): void
    {
        [$environment, $resource] = $this->environment('production');
        $other = Project::query()->create(['workspace_id' => $this->coreWorkspace->id, 'name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        ProjectMembership::query()->create(['project_id' => $other->id, 'user_id' => $this->actor->id, 'role' => 'owner', 'status' => 'active']);
        ProjectProduct::query()->create(['project_id' => $other->id, 'product' => 'monitor', 'status' => 'active']);
        $this->resource->update(['project_id' => $other->id, 'status' => 'active']);
        LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'application')->update(['canonical_id' => $other->id]);
        app(ArchiveEnvironment::class)->archive($environment);

        $this->expectException(ResourceRestorationBlocked::class);
        app(RequestResourceRestoration::class)->request($this->actor, $resource, (string) Str::uuid());
    }

    public function test_imported_application_attachment_is_reactivated_without_changing_grants(): void
    {
        $this->product->update(['status' => 'inactive', 'metadata' => ['migration_source' => 'monitor', 'archive_origin' => 'parent_application', 'source_application_id' => (string) $this->application->id]]);
        $beforeGrant = $this->grant->fresh()->getAttributes();
        app(ArchiveApplication::class)->archive($this->application);
        $request = $this->request();

        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($request->id));
        $this->assertSame('active', $this->product->fresh()->status);
        $this->assertSame($beforeGrant, $this->grant->fresh()->getAttributes());
    }

    public function test_inactive_product_without_archive_provenance_requires_reconciliation(): void
    {
        $this->product->update(['status' => 'inactive', 'metadata' => ['migration_source' => 'monitor']]);
        app(ArchiveApplication::class)->archive($this->application);

        $this->expectException(ResourceRestorationBlocked::class);
        $this->request();
    }

    private function request(): ResourceRestorationRequest
    {
        return app(RequestResourceRestoration::class)->request($this->actor, $this->resource->fresh(), (string) Str::uuid());
    }

    private function claim(ResourceRestorationRequest $request): ResourceRestorationRequest
    {
        $request->forceFill(['status' => 'processing', 'attempts' => 1, 'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(10)])->save();

        return $request;
    }

    /** @return array{Environment, ProjectResource, ProjectEnvironment} */
    private function environment(string $name, string $status = 'active'): array
    {
        $environment = Environment::query()->forceCreate(['application_id' => $this->application->id, 'name' => ucfirst($name), 'slug' => $name, 'status' => $status]);
        $metadata = ['source_application_id' => (string) $this->application->id, 'archive_origin' => 'parent_application'];
        $canonical = ProjectEnvironment::query()->create(['project_id' => $this->project->id, 'name' => ucfirst($name), 'slug' => $name, 'status' => 'archived', 'metadata' => $metadata]);
        $resource = ProjectResource::query()->create(['project_id' => $this->project->id, 'environment_id' => $canonical->id, 'product' => 'monitor', 'resource_type' => 'environment', 'resource_id' => (string) $environment->id, 'status' => 'archived', 'metadata' => $metadata]);
        $this->identity('environment', $environment->id, 'project_environment', $canonical->id);

        return [$environment, $resource, $canonical];
    }

    private function identity(string $entity, int $sourceId, string $canonicalEntity, string $canonicalId): void
    {
        LegacyIdentityMap::query()->create(['source_product' => 'monitor', 'source_entity' => $entity, 'source_id' => (string) $sourceId, 'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId, 'status' => 'reconciled']);
    }
}
