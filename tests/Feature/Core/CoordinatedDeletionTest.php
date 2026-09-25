<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProductDeletionProvider;
use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionPreview;
use App\Core\Data\Deletion\ProductDeletionResult;
use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Exceptions\Deletion\DeletionBlocked;
use App\Core\Models\DeletionRequest;
use App\Core\Models\DeletionStep;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformAuthSession;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProductBillingEvent;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Services\Deletion\DeletionAuthority;
use App\Core\Services\Deletion\DeletionPlanner;
use App\Core\Services\Deletion\ProcessDeletion;
use App\Core\Services\Deletion\ProductDeletionRegistry;
use App\Core\Services\Deletion\RequestDeletion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CoordinatedDeletionTest extends TestCase
{
    private PlatformUser $actor;

    private Workspace $workspace;

    private DeletionProviderFixture $provider;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.core.database' => ':memory:']);
        DB::purge('core');
        Artisan::call('platform:migrate', ['module' => 'core']);

        $this->actor = PlatformUser::query()->forceCreate([
            'name' => 'Deletion owner',
            'email' => 'deletion-owner@example.test',
            'email_normalized' => 'deletion-owner@example.test',
            'status' => 'active',
        ]);
        $this->workspace = $this->makeOwnedWorkspace($this->actor, 'Primary workspace');
        $this->addMonitorMappings($this->actor, $this->workspace, 'native-account-1', 'native-workspace-1');
        $project = Project::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by_user_id' => $this->actor->getKey(),
            'name' => 'Private project name',
            'slug' => 'private-project',
            'status' => 'active',
            'description' => 'Private project details',
        ]);
        ProjectProduct::query()->create(['project_id' => $project->getKey(), 'product' => 'monitor', 'status' => 'active']);

        $session = PlatformAuthSession::query()->create(['user_id' => $this->actor->getKey(), 'last_seen_at' => now()]);
        $this->sessionId = (string) $session->getKey();
        $this->provider = new DeletionProviderFixture;
        $registry = new ProductDeletionRegistry;
        $registry->register($this->provider);
        app()->instance(ProductDeletionRegistry::class, $registry);
    }

    public function test_preflight_requires_the_owned_workspace_and_reports_paid_or_pending_billing_without_writes(): void
    {
        $otherOwner = $this->makeActor('other-owner@example.test');
        $otherWorkspace = $this->makeOwnedWorkspace($otherOwner, 'Other workspace');

        try {
            app(DeletionPlanner::class)->plan($this->actor, $otherWorkspace);
            $this->fail('A non-owner must not preflight another owner’s workspace.');
        } catch (DeletionBlocked $exception) {
            $this->assertSame('workspace_owner_required', $exception->reasonCode);
        }

        ProductSubscription::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'product' => 'monitor',
            'provider' => 'fixture',
            'provider_subscription_id' => 'paid-subscription-1',
            'status' => 'active',
        ]);
        $plan = app(DeletionPlanner::class)->plan($this->actor, $this->workspace);
        $this->assertContains('settle_product_billing', $plan['blockers']);
        ProductSubscription::query()->delete();
        ProductBillingEvent::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'product' => 'monitor',
            'provider' => 'fixture',
            'provider_event_id' => 'pending-event-1',
            'event_type' => 'subscription.updated',
            'processing_status' => 'pending',
        ]);

        $plan = app(DeletionPlanner::class)->plan($this->actor, $this->workspace);

        $this->assertContains('pending_billing_reconciliation', $plan['blockers']);
        $this->assertSame('active', $this->workspace->refresh()->status);
        $this->assertSame('active', $this->actor->refresh()->status);
        $this->assertSame(0, DeletionRequest::query()->count());
        $this->assertSame(0, DeletionStep::query()->count());
    }

    public function test_account_preflight_blocks_foreign_memberships(): void
    {
        $otherOwner = $this->makeActor('foreign-owner@example.test');
        $foreignWorkspace = $this->makeOwnedWorkspace($otherOwner, 'Foreign workspace');
        WorkspaceMembership::query()->create([
            'workspace_id' => $foreignWorkspace->getKey(),
            'user_id' => $this->actor->getKey(),
            'status' => 'active',
            'role' => 'viewer',
        ]);

        $plan = app(DeletionPlanner::class)->plan($this->actor);

        $this->assertContains('leave_shared_workspaces', $plan['blockers']);
        $this->assertSame('active', $this->workspace->refresh()->status);
        $this->assertSame('active', $foreignWorkspace->refresh()->status);
    }

    public function test_all_targets_prepare_before_workspace_then_account_purge(): void
    {
        $request = $this->acceptAccountDeletion();
        $workspaceStep = $request->steps()->where('kind', 'workspace')->firstOrFail();
        $accountStep = $request->steps()->where('kind', 'account')->firstOrFail();
        $processor = app(ProcessDeletion::class);

        $this->assertSame('ready', $processor->process($workspaceStep->getKey()));
        $this->assertSame('prepare', $request->refresh()->phase);
        $this->assertSame('ready', $processor->process($accountStep->getKey()));
        $this->assertSame('purge', $request->refresh()->phase);
        $this->assertSame(['prepare:workspace', 'prepare:account'], $this->provider->events);

        $this->assertSame('skipped', $processor->process($accountStep->getKey()));
        $this->assertSame([], $this->provider->purgeKinds);
        $this->assertSame('completed', $processor->process($workspaceStep->getKey()));
        $this->assertSame(['workspace'], $this->provider->purgeKinds);
        $this->assertSame('completed', $processor->process($accountStep->getKey()));
        $this->assertSame(['workspace', 'account'], $this->provider->purgeKinds);
        $this->assertSame('completed', $request->refresh()->status);
    }

    public function test_retry_preserves_step_and_payload_ids_while_issuing_a_new_generation_and_lease(): void
    {
        $request = $this->acceptWorkspaceDeletion();
        $step = $request->steps()->where('kind', 'workspace')->firstOrFail();
        $this->provider->prepareResults = [new ProductDeletionResult('waiting', 'native_busy'), new ProductDeletionResult('ready')];
        $processor = app(ProcessDeletion::class);

        $this->assertSame('pending', $processor->process($step->getKey()));
        $firstAttempt = $this->provider->prepareAttempts[0];
        $step->refresh()->forceFill(['available_at' => now()->subSecond()])->save();
        $this->assertSame('ready', $processor->process($step->getKey()));
        $secondAttempt = $this->provider->prepareAttempts[1];

        $this->assertSame($firstAttempt->stepId, $secondAttempt->stepId);
        $this->assertSame($firstAttempt->payloadHash, $secondAttempt->payloadHash);
        $this->assertSame($firstAttempt->requestId, $secondAttempt->requestId);
        $this->assertSame($firstAttempt->generation + 1, $secondAttempt->generation);
        $this->assertNotSame($firstAttempt->leaseToken, $secondAttempt->leaseToken);
    }

    public function test_native_purge_waits_for_a_claimed_cross_app_delivery_even_when_its_timestamp_is_old(): void
    {
        $project = Project::query()->where('workspace_id', $this->workspace->getKey())->firstOrFail();
        $source = ProjectResource::query()->create([
            'project_id' => $project->getKey(), 'product' => 'monitor',
            'resource_type' => 'application', 'resource_id' => 'native-app', 'status' => 'active',
        ]);
        $target = ProjectResource::query()->create([
            'project_id' => $project->getKey(), 'product' => 'monitor',
            'resource_type' => 'environment', 'resource_id' => 'native-environment', 'status' => 'active',
        ]);
        $connection = ProjectConnection::query()->create([
            'project_id' => $project->getKey(), 'source_resource_id' => $source->getKey(),
            'target_resource_id' => $target->getKey(), 'capabilities' => [], 'status' => 'active',
        ]);
        $delivery = ProjectConnectionDelivery::query()->create([
            'project_connection_id' => $connection->getKey(), 'source_event_id' => (string) Str::ulid(),
            'event_type' => 'fixture', 'event_version' => 1, 'payload' => [], 'status' => 'processing',
            'last_attempted_at' => now()->subDay(),
        ]);
        $request = $this->acceptWorkspaceDeletion();
        $step = $request->steps()->firstOrFail();
        $processor = app(ProcessDeletion::class);

        $this->assertSame('ready', $processor->process($step->getKey()));
        $this->assertSame('prepare', $request->refresh()->phase);
        $this->assertSame('core_activity_draining', $request->last_error_code);
        $this->assertSame([], $this->provider->purgeKinds);
        $this->assertSame('disconnected', $connection->refresh()->status);

        $delivery->forceFill(['status' => 'discarded'])->save();
        $processor->advance($request->getKey());

        $this->assertSame('purge', $request->refresh()->phase);
        $this->assertSame('completed', $processor->process($step->getKey()));
    }

    public function test_a_billing_obligation_arriving_after_preparation_blocks_purge_until_reconciled(): void
    {
        $request = $this->acceptWorkspaceDeletion();
        $step = $request->steps()->firstOrFail();
        $processor = app(ProcessDeletion::class);
        $this->assertSame('ready', $processor->process($step->getKey()));
        $subscription = ProductSubscription::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'product' => 'monitor', 'provider' => 'fixture',
            'provider_subscription_id' => 'late-obligation', 'status' => 'active',
        ]);

        $this->assertSame('blocked', $processor->process($step->getKey()));
        $this->assertSame('settle_product_billing', $step->refresh()->last_error_code);
        $this->assertSame([], $this->provider->purgeKinds);

        $subscription->forceFill(['status' => 'canceled', 'current_period_ends_at' => now()->subDay()])->save();
        $processor->retry($request);
        $this->assertSame('completed', $processor->process($step->getKey()));
        $this->assertSame('completed', $request->refresh()->status);
    }

    public function test_expired_and_superseded_leases_are_rejected_by_deletion_authority(): void
    {
        $request = $this->acceptWorkspaceDeletion();
        $step = $request->steps()->where('kind', 'workspace')->firstOrFail();
        $step->forceFill([
            'status' => 'processing',
            'attempts' => 1,
            'lease_token' => 'lease-expired',
            'lease_expires_at' => now()->subSecond(),
        ])->save();
        $expired = $step->fresh()->attempt();

        try {
            app(DeletionAuthority::class)->assertAttempt($expired);
            $this->fail('An expired lease must not authorize a product operation.');
        } catch (DeletionBlocked $exception) {
            $this->assertSame('deletion_attempt_stale', $exception->reasonCode);
        }

        $step->forceFill(['lease_expires_at' => now()->addMinute(), 'lease_token' => 'newer-lease'])->save();

        try {
            app(DeletionAuthority::class)->assertAttempt($expired);
            $this->fail('A stale lease token must not authorize a product operation.');
        } catch (DeletionBlocked $exception) {
            $this->assertSame('deletion_attempt_stale', $exception->reasonCode);
        }
    }

    public function test_identity_binding_change_after_acceptance_blocks_cleanup(): void
    {
        $request = $this->acceptWorkspaceDeletion();
        LegacyIdentityMap::query()->where('source_product', 'monitor')->where('source_entity', 'workspace')
            ->update(['status' => 'needs_review']);
        $step = $request->steps()->where('kind', 'workspace')->firstOrFail();

        $this->assertSame('blocked', app(ProcessDeletion::class)->process($step->getKey()));
        $this->assertSame('deletion_identity_changed', $step->refresh()->last_error_code);
        $this->assertSame([], $this->provider->events);
    }

    public function test_final_scrub_retains_minimal_identity_tombstone_and_preserves_unrelated_workspace(): void
    {
        $otherOwner = $this->makeActor('unrelated-owner@example.test');
        $unrelated = $this->makeOwnedWorkspace($otherOwner, 'Unrelated workspace');
        $this->addMonitorMappings($otherOwner, $unrelated, 'unrelated-native-account', 'unrelated-native-workspace');
        $unrelatedProject = Project::query()->create([
            'workspace_id' => $unrelated->getKey(),
            'created_by_user_id' => $otherOwner->getKey(),
            'name' => 'Keep this project',
            'slug' => 'keep-this-project',
            'status' => 'active',
        ]);
        $request = $this->acceptAccountDeletion();
        $processor = app(ProcessDeletion::class);

        foreach ($request->steps()->orderBy('kind')->get() as $step) {
            $this->assertSame('ready', $processor->process($step->getKey()));
        }
        foreach ($request->steps()->where('kind', 'workspace')->get() as $step) {
            $this->assertSame('completed', $processor->process($step->getKey()));
        }
        foreach ($request->steps()->where('kind', 'account')->get() as $step) {
            $this->assertSame('completed', $processor->process($step->getKey()));
        }

        $this->assertSame('completed', $request->refresh()->status);
        $this->assertNull($this->actor->fresh()->email);
        $this->assertSame('Deleted workspace', $this->workspace->fresh()->name);
        $this->assertSame('active', $unrelated->refresh()->status);
        $this->assertSame('Keep this project', $unrelatedProject->refresh()->name);
        $this->assertSame('reconciled', LegacyIdentityMap::query()->where('canonical_id', $otherOwner->getKey())->value('status'));
        $this->assertSame('deleted', LegacyIdentityMap::query()->where('canonical_entity', 'user')->where('canonical_id', $this->actor->getKey())->value('status'));
    }

    private function acceptWorkspaceDeletion(): DeletionRequest
    {
        return $this->acceptDeletion($this->workspace);
    }

    private function acceptAccountDeletion(): DeletionRequest
    {
        return $this->acceptDeletion(null);
    }

    private function acceptDeletion(?Workspace $workspace): DeletionRequest
    {
        $plan = app(DeletionPlanner::class)->plan($this->actor, $workspace);
        $this->assertSame([], $plan['blockers']);

        return app(RequestDeletion::class)->request($this->actor, $workspace, [
            'idempotency_key' => (string) Str::uuid(),
            'fingerprint' => $plan['fingerprint'],
            'confirmation' => $workspace === null ? $this->actor->email : $workspace->name,
            'understood' => true,
        ], str_repeat('a', 64), $this->sessionId);
    }

    private function makeActor(string $email): PlatformUser
    {
        return PlatformUser::query()->forceCreate([
            'name' => 'Fixture owner',
            'email' => $email,
            'email_normalized' => $email,
            'status' => 'active',
        ]);
    }

    private function makeOwnedWorkspace(PlatformUser $owner, string $name): Workspace
    {
        $workspace = Workspace::query()->create([
            'owner_user_id' => $owner->getKey(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => 'active',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $owner->getKey(),
            'status' => 'active',
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return $workspace;
    }

    private function addMonitorMappings(PlatformUser $owner, Workspace $workspace, string $userSource, string $workspaceSource): void
    {
        LegacyIdentityMap::query()->create([
            'source_product' => 'monitor', 'source_entity' => 'user', 'source_id' => $userSource,
            'canonical_entity' => 'user', 'canonical_id' => $owner->getKey(), 'status' => 'reconciled',
        ]);
        LegacyIdentityMap::query()->create([
            'source_product' => 'monitor', 'source_entity' => 'workspace', 'source_id' => $workspaceSource,
            'canonical_entity' => 'workspace', 'canonical_id' => $workspace->getKey(), 'status' => 'reconciled',
        ]);
    }
}

final class DeletionProviderFixture implements ProductDeletionProvider
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<string> */
    public array $purgeKinds = [];

    /** @var list<ProductDeletionAttempt> */
    public array $prepareAttempts = [];

    /** @var list<ProductDeletionResult> */
    public array $prepareResults = [];

    public function product(): string
    {
        return 'monitor';
    }

    public function inspect(ProductDeletionTarget $target): ProductDeletionPreview
    {
        return new ProductDeletionPreview;
    }

    public function prepare(ProductDeletionAttempt $attempt): ProductDeletionResult
    {
        $this->events[] = 'prepare:'.$attempt->target->kind;
        $this->prepareAttempts[] = $attempt;

        return array_shift($this->prepareResults) ?? new ProductDeletionResult('ready');
    }

    public function purge(ProductDeletionAttempt $attempt): ProductDeletionResult
    {
        $this->events[] = 'purge:'.$attempt->target->kind;
        $this->purgeKinds[] = $attempt->target->kind;

        return new ProductDeletionResult('completed', null, ['Native cleanup receipt retained.']);
    }
}
