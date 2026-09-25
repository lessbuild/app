<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProductResourceRestorationProvider;
use App\Core\Data\Restoration\NativeRestorationReceipt;
use App\Core\Data\Restoration\NativeRestorationSnapshot;
use App\Core\Data\Restoration\NativeRestorationState;
use App\Core\Data\Restoration\ResourceRestorationAttempt;
use App\Core\Data\Restoration\ResourceRestorationTarget;
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
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Restoration\ProcessResourceRestoration;
use App\Core\Services\Restoration\ProductResourceRestorationRegistry;
use App\Core\Services\Restoration\RequestResourceRestoration;
use App\Core\Services\Restoration\ResourceRestorationAuthority;
use App\Core\Services\Restoration\RetryResourceRestoration;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class ResourceRestorationTest extends TestCase
{
    private PlatformUser $actor;

    private Project $project;

    private ProjectResource $resource;

    private WorkspaceProductAccess $grant;

    private RestorationProviderFixture $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.core.database' => ':memory:', 'platform.products.monitor.enabled' => true]);
        DB::purge('core');
        Artisan::call('platform:migrate', ['module' => 'core']);
        $this->actor = PlatformUser::query()->forceCreate(['name' => 'Manager', 'email' => 'restore@example.test', 'email_normalized' => 'restore@example.test', 'status' => 'active']);
        $workspace = Workspace::query()->create(['owner_user_id' => $this->actor->getKey(), 'name' => 'Team', 'slug' => 'restore-team', 'status' => 'active']);
        $member = WorkspaceMembership::query()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $this->actor->getKey(), 'status' => 'active', 'role' => 'owner']);
        $this->grant = WorkspaceProductAccess::query()->create(['membership_id' => $member->getKey(), 'product' => 'monitor', 'status' => 'active', 'role' => 'owner']);
        $this->project = Project::query()->create(['workspace_id' => $workspace->getKey(), 'name' => 'App', 'slug' => 'restored-app', 'status' => 'active']);
        ProjectMembership::query()->create(['project_id' => $this->project->getKey(), 'user_id' => $this->actor->getKey(), 'status' => 'active', 'role' => 'owner']);
        ProjectProduct::query()->create(['project_id' => $this->project->getKey(), 'product' => 'monitor', 'status' => 'active']);
        LegacyIdentityMap::query()->create(['source_product' => 'monitor', 'source_entity' => 'workspace', 'source_id' => '1', 'canonical_entity' => 'workspace', 'canonical_id' => $workspace->getKey(), 'status' => 'reconciled', 'batch_key' => 'fixture']);
        $this->resource = ProjectResource::query()->create(['project_id' => $this->project->getKey(), 'product' => 'monitor', 'resource_type' => 'application', 'resource_id' => '10', 'name' => 'App', 'status' => 'archived']);
        LegacyIdentityMap::query()->create(['source_product' => 'monitor', 'source_entity' => 'user', 'source_id' => '100', 'canonical_entity' => 'user', 'canonical_id' => $this->actor->getKey(), 'status' => 'reconciled', 'batch_key' => 'fixture']);
        $this->provider = new RestorationProviderFixture;
        $registry = new ProductResourceRestorationRegistry;
        $registry->register($this->provider);
        app()->instance(ProductResourceRestorationRegistry::class, $registry);
    }

    public function test_request_commits_before_source_and_projects_exact_explicit_mapping_without_legacy_map(): void
    {
        $request = $this->request();
        $this->assertSame('pending', $request->status);
        $this->assertSame(0, $this->provider->writes);
        $this->provider->beforeApply = function (ResourceRestorationAttempt $attempt): void {
            $this->assertSame(0, DB::connection('core')->transactionLevel());
            $this->assertSame('processing', ResourceRestorationRequest::query()->findOrFail($attempt->requestId)->status);
        };
        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($request->getKey()));
        $this->assertSame('active', $this->resource->refresh()->status);
        $this->assertSame('active', $this->grant->refresh()->status);
        $this->assertSame(1, $this->provider->writes);
    }

    public function test_source_commit_interruption_retries_receipt_without_duplicate_source_mutation(): void
    {
        $request = $this->request();
        $this->provider->interruptProjection = true;
        $this->assertSame('pending', app(ProcessResourceRestoration::class)->process($request->getKey()));
        $this->assertSame('archived', $this->resource->refresh()->status);
        $this->assertSame('restoration_temporarily_unavailable', $request->refresh()->last_error_code);
        $duplicate = app(RequestResourceRestoration::class)->request($this->actor, $this->resource, $request->idempotency_key);
        $this->assertSame($request->getKey(), $duplicate->getKey());
        $this->assertTrue(app(RetryResourceRestoration::class)->retry($this->actor, $request));
        $this->provider->interruptProjection = false;
        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($request->getKey()));
        $this->assertSame(1, $this->provider->writes);
        $this->assertSame($request->getKey(), app(RequestResourceRestoration::class)->request($this->actor, $this->resource, $request->idempotency_key)->getKey());
    }

    public function test_same_idempotency_key_cannot_bind_a_changed_source_revision(): void
    {
        $request = $this->request();
        $this->provider->revision++;
        $this->expectException(ValidationException::class);
        app(RequestResourceRestoration::class)->request($this->actor, $this->resource, $request->idempotency_key);
    }

    public function test_rearchive_after_source_receipt_supersedes_without_projection(): void
    {
        $request = $this->request();
        $this->provider->beforeProjection = function (): void {
            $this->provider->revision++;
        };
        $this->assertSame('superseded', app(ProcessResourceRestoration::class)->process($request->getKey()));
        $this->assertSame('archived', $this->resource->refresh()->status);
        $this->assertNull($request->refresh()->completed_at);
    }

    public function test_current_revoked_grant_blocks_before_source_and_demoted_role_blocks_projection(): void
    {
        $request = $this->request();
        $this->grant->update(['revoked_at' => now()]);
        $this->assertSame('blocked', app(ProcessResourceRestoration::class)->process($request->getKey()));
        $this->assertSame(0, $this->provider->writes);
        $this->grant->update(['revoked_at' => null]);
        app(RetryResourceRestoration::class)->retry($this->actor, $request->refresh());
        $this->provider->beforeProjection = fn () => $this->grant->update(['role' => 'viewer']);
        $this->assertSame('blocked', app(ProcessResourceRestoration::class)->process($request->getKey()));
        $this->assertSame('archived', $this->resource->refresh()->status);
    }

    public function test_expired_attempt_cannot_complete_or_record_failure_over_new_claim(): void
    {
        $request = $this->request();
        $stale = null;
        $this->provider->beforeApply = function (ResourceRestorationAttempt $attempt) use (&$stale, $request): void {
            $stale = $attempt;
            ResourceRestorationRequest::query()->whereKey($request->getKey())->update(['lease_expires_at' => now()->subSecond()]);
            $this->assertSame(1, app(ProcessResourceRestoration::class)->recoverExpiredLeases());
        };
        $this->assertSame('skipped', app(ProcessResourceRestoration::class)->process($request->getKey()));
        $this->assertSame('pending', $request->refresh()->status);
        $this->provider->beforeApply = null;
        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($request->getKey()));
        $this->assertSame(2, $request->refresh()->attempts);
        $this->expectException(ResourceRestorationBlocked::class);
        app(ResourceRestorationAuthority::class)->assertAttempt($stale);
    }

    public function test_new_child_mapping_after_request_is_detected_before_source(): void
    {
        $this->provider->states[] = new NativeRestorationState('environment', '11', 'active');
        $request = $this->request();
        $this->child('11', 'active');
        $this->assertSame('blocked', app(ProcessResourceRestoration::class)->process($request->getKey()));
        $this->assertSame(0, $this->provider->writes);
    }

    public function test_truthful_children_preserve_native_pauses_deletions_and_independent_canonical_archival(): void
    {
        [$active, $activeEnvironment] = $this->child('11', 'archived', 'parent_application');
        [$paused] = $this->child('12', 'archived', 'parent_application');
        [$deleted] = $this->child('13', 'archived', 'native_resource');
        [$independent] = $this->child('14', 'archived');
        $this->provider->states = [new NativeRestorationState('application', '10', 'active'), new NativeRestorationState('environment', '11', 'active'), new NativeRestorationState('environment', '12', 'paused'), new NativeRestorationState('environment', '13', 'archived'), new NativeRestorationState('environment', '14', 'active')];
        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($this->request()->getKey()));
        $this->assertSame('active', $active->refresh()->status);
        $this->assertSame('active', $activeEnvironment->refresh()->status);
        $this->assertSame('paused', $paused->refresh()->status);
        $this->assertSame('archived', $deleted->refresh()->status);
        $this->assertSame('archived', $independent->refresh()->status);
    }

    public function test_archived_shared_environment_blocks_request_before_source(): void
    {
        [, $environment] = $this->child('11', 'archived', 'parent_application');
        ProjectResource::query()->create(['project_id' => $this->project->getKey(), 'environment_id' => $environment->getKey(), 'product' => 'deployer', 'resource_type' => 'environment', 'resource_id' => '90', 'name' => 'Shared', 'status' => 'archived']);
        $this->provider->states[] = new NativeRestorationState('environment', '11', 'active');
        try {
            $this->request();
            $this->fail('Shared archived environment must require reconciliation.');
        } catch (ResourceRestorationBlocked $exception) {
            $this->assertSame('shared_environment_reconciliation_required', $exception->reasonCode);
        }
        $this->assertSame(0, $this->provider->writes);
        $this->assertSame(0, ResourceRestorationRequest::query()->count());
    }

    public function test_legacy_identity_only_child_is_not_treated_as_unmapped(): void
    {
        $this->provider->states[] = new NativeRestorationState('environment', '11', 'active');
        $environment = ProjectEnvironment::query()->create(['project_id' => $this->project->getKey(), 'name' => 'Old', 'slug' => 'old', 'environment_type' => 'custom', 'status' => 'archived']);
        LegacyIdentityMap::query()->create(['source_product' => 'monitor', 'source_entity' => 'environment', 'source_id' => '11', 'canonical_entity' => 'project_environment', 'canonical_id' => $environment->getKey(), 'status' => 'reconciled', 'batch_key' => 'fixture']);
        $this->expectException(ResourceRestorationBlocked::class);
        $this->request();
    }

    public function test_archived_project_is_never_implicitly_restored(): void
    {
        $this->project->update(['status' => 'archived', 'archived_at' => now()]);
        $this->expectException(ResourceRestorationBlocked::class);
        $this->request();
    }

    public function test_request_intent_cannot_be_mutated(): void
    {
        $request = $this->request();
        $this->expectException(\LogicException::class);
        $request->update(['expected_revision' => 99]);
    }

    public function test_progress_and_retry_require_current_access_and_hide_secret_payload_fields(): void
    {
        $request = $this->request();
        $this->actingAs($this->actor, 'platform')->get(route('platform.resource-restorations.show', $request))
            ->assertOk()->assertSee('Queued')->assertDontSee($request->payload_hash)->assertDontSee($request->mapping_fingerprint);
        $this->grant->update(['revoked_at' => now()]);
        $this->get(route('platform.resource-restorations.show', $request))->assertNotFound();
        $this->post(route('platform.resource-restorations.retry', $request))->assertNotFound();
    }

    public function test_current_replacement_manager_creates_a_successor_without_rewriting_original_actor(): void
    {
        $request = $this->request();
        $request->update(['status' => 'blocked']);
        $replacement = PlatformUser::query()->forceCreate(['name' => 'New manager', 'email' => 'new-manager@example.test', 'email_normalized' => 'new-manager@example.test', 'status' => 'active']);
        $member = WorkspaceMembership::query()->create(['workspace_id' => $this->project->workspace_id, 'user_id' => $replacement->getKey(), 'status' => 'active', 'role' => 'admin']);
        WorkspaceProductAccess::query()->create(['membership_id' => $member->getKey(), 'product' => 'monitor', 'status' => 'active', 'role' => 'admin']);
        ProjectMembership::query()->create(['project_id' => $this->project->getKey(), 'user_id' => $replacement->getKey(), 'status' => 'active', 'role' => 'admin']);
        LegacyIdentityMap::query()->create(['source_product' => 'monitor', 'source_entity' => 'user', 'source_id' => '101', 'canonical_entity' => 'user', 'canonical_id' => $replacement->getKey(), 'status' => 'reconciled', 'batch_key' => 'fixture']);
        $this->grant->update(['revoked_at' => now()]);
        $this->actingAs($replacement, 'platform')->post(route('platform.resource-restorations.retry', $request))->assertRedirect();
        $this->assertSame((string) $this->actor->getKey(), $request->refresh()->actor_id);
        $successor = ResourceRestorationRequest::query()->where('actor_id', $replacement->getKey())->sole();
        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($successor->getKey()));
        $this->assertSame('blocked', $request->refresh()->status);
    }

    public function test_repeated_expired_leases_eventually_need_manual_retry(): void
    {
        $request = $this->request();
        $request->update(['status' => 'processing', 'attempts' => ProcessResourceRestoration::MAX_ATTEMPTS, 'lease_token' => 'abandoned', 'lease_expires_at' => now()->subMinute()]);
        $this->assertSame(1, app(ProcessResourceRestoration::class)->recoverExpiredLeases());
        $this->assertSame('failed', $request->refresh()->status);
        $this->assertNull($request->available_at);
        $this->assertTrue(app(RetryResourceRestoration::class)->retry($this->actor, $request));
        $this->assertSame(ProcessResourceRestoration::MAX_ATTEMPTS, $request->refresh()->attempts);
    }

    public function test_shared_active_and_paused_environments_keep_canonical_state_while_monitor_resource_restores(): void
    {
        [$pausedMonitor, $activeEnvironment] = $this->child('11', 'active');
        [$activeMonitor, $pausedEnvironment] = $this->child('12', 'paused');
        foreach ([$activeEnvironment, $pausedEnvironment] as $environment) {
            ProjectResource::query()->create(['project_id' => $this->project->getKey(), 'environment_id' => $environment->getKey(), 'product' => 'deployer', 'resource_type' => 'environment', 'resource_id' => 'peer-'.$environment->getKey(), 'name' => 'Peer', 'status' => 'active']);
        }
        $this->provider->states = [new NativeRestorationState('application', '10', 'active'), new NativeRestorationState('environment', '11', 'paused'), new NativeRestorationState('environment', '12', 'active')];
        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($this->request()->getKey()));
        $this->assertSame('paused', $pausedMonitor->refresh()->status);
        $this->assertSame('active', $activeEnvironment->refresh()->status);
        $this->assertSame('active', $activeMonitor->refresh()->status);
        $this->assertSame('paused', $pausedEnvironment->refresh()->status);
    }

    public function test_same_manager_can_start_successor_after_old_fingerprint_is_reconciled(): void
    {
        $request = $this->request();
        $request->update(['status' => 'blocked']);
        $this->resource->update(['metadata' => ['source_application_id' => '10']]);
        $this->actingAs($this->actor, 'platform')->post(route('platform.resource-restorations.retry', $request))->assertRedirect();
        $successor = ResourceRestorationRequest::query()->where('id', '!=', $request->getKey())->sole();
        $this->assertSame($request->actor_id, $successor->actor_id);
        $this->assertNotSame($request->mapping_fingerprint, $successor->mapping_fingerprint);
        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($successor->getKey()));
    }

    public function test_native_user_identity_revocation_after_source_commit_blocks_projection(): void
    {
        $request = $this->request();
        $this->provider->beforeProjection = fn () => LegacyIdentityMap::query()->where('source_entity', 'user')->update(['status' => 'needs_review']);
        $this->assertSame('blocked', app(ProcessResourceRestoration::class)->process($request->getKey()));
        $this->assertSame('archived', $this->resource->refresh()->status);
    }

    public function test_two_monitor_children_sharing_canonical_environment_do_not_overwrite_each_others_state(): void
    {
        [$first, $environment] = $this->child('11', 'active');
        $second = ProjectResource::query()->create(['project_id' => $this->project->getKey(), 'environment_id' => $environment->getKey(), 'product' => 'monitor', 'resource_type' => 'environment', 'resource_id' => '12', 'name' => 'Second child', 'status' => 'paused', 'metadata' => ['source_application_id' => '10']]);
        $this->provider->states = [new NativeRestorationState('application', '10', 'active'), new NativeRestorationState('environment', '11', 'paused'), new NativeRestorationState('environment', '12', 'active')];
        $this->assertSame('completed', app(ProcessResourceRestoration::class)->process($this->request()->getKey()));
        $this->assertSame('paused', $first->refresh()->status);
        $this->assertSame('active', $second->refresh()->status);
        $this->assertSame('active', $environment->refresh()->status);
    }

    private function request(): ResourceRestorationRequest
    {
        return app(RequestResourceRestoration::class)->request($this->actor, $this->resource, (string) Str::uuid());
    }

    private function child(string $id, string $status, ?string $origin = null): array
    {
        $metadata = ['source_application_id' => '10', 'archive_origin' => $origin];
        $environment = ProjectEnvironment::query()->create(['project_id' => $this->project->getKey(), 'name' => 'Child '.$id, 'slug' => 'child-'.$id, 'environment_type' => 'custom', 'status' => $status, 'metadata' => $metadata]);
        $resource = ProjectResource::query()->create(['project_id' => $this->project->getKey(), 'environment_id' => $environment->getKey(), 'product' => 'monitor', 'resource_type' => 'environment', 'resource_id' => $id, 'name' => 'Child '.$id, 'status' => $status, 'metadata' => $metadata]);

        return [$resource, $environment];
    }
}

final class RestorationProviderFixture implements ProductResourceRestorationProvider
{
    public int $revision = 7;

    public int $writes = 0;

    public bool $interruptProjection = false;

    public ?Closure $beforeApply = null;

    public ?Closure $beforeProjection = null;

    public array $states;

    private array $receipts = [];

    public function __construct()
    {
        $this->states = [new NativeRestorationState('application', '10', 'active')];
    }

    public function product(): string
    {
        return 'monitor';
    }

    public function resourceTypes(): array
    {
        return ['application', 'environment'];
    }

    public function inspect(PlatformUser $actor, ResourceRestorationTarget $target, ?string $receiptRequestId = null): NativeRestorationSnapshot
    {
        $receipt = $this->receipts[$receiptRequestId] ?? null;

        return new NativeRestorationSnapshot($this->revision, $this->states, $receipt?->revision === $this->revision ? $receipt : null);
    }

    public function apply(ResourceRestorationAttempt $attempt): NativeRestorationReceipt
    {
        ($this->beforeApply ?? static fn () => null)($attempt);
        app(ResourceRestorationAuthority::class)->assertAttempt($attempt);
        if (isset($this->receipts[$attempt->requestId])) {
            return $this->receipts[$attempt->requestId];
        }
        if ($attempt->expectedRevision !== $this->revision) {
            throw new ResourceRestorationSuperseded;
        }
        $this->writes++;

        return $this->receipts[$attempt->requestId] = new NativeRestorationReceipt($attempt->requestId, ++$this->revision, $this->states);
    }

    public function withCurrentReceipt(ResourceRestorationAttempt $attempt, Closure $commit): void
    {
        if ($this->interruptProjection) {
            throw new RuntimeException('Sensitive native details must never be persisted.');
        }
        ($this->beforeProjection ?? static fn () => null)();
        $receipt = $this->receipts[$attempt->requestId];
        if ($receipt->revision !== $this->revision) {
            throw new ResourceRestorationSuperseded;
        }
        $commit($receipt);
    }
}
