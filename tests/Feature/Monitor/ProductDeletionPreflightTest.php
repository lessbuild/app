<?php

namespace Tests\Feature\Monitor;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Exceptions\Deletion\DeletionBlocked;
use App\Core\Models\DeletionRequest;
use App\Core\Models\DeletionStep;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Monitor\Models\BillingEvent;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Core\MonitorProductDeletionProvider;
use App\Modules\Monitor\Services\MonitorPlanAuthority;
use App\Modules\Monitor\Services\ProcessStripeBillingEvent;
use App\Modules\Monitor\Services\StripeBillingClient;
use App\Modules\Monitor\Services\SyncMonitorBillingEventIntoCore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Authored only: execution remains deferred until the full source plan is complete. */
final class ProductDeletionPreflightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['core', 'monitor'] as $connection) {
            config(["database.connections.{$connection}.database" => ':memory:']);
            DB::purge($connection);
            $this->assertSame(0, Artisan::call('platform:migrate', ['module' => $connection]));
        }
    }

    public function test_workspace_preflight_reports_teammates_and_unsettled_stripe_billing_without_fencing(): void
    {
        $owner = User::query()->forceCreate(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'unused']);
        $teammate = User::query()->forceCreate(['name' => 'Teammate', 'email' => 'teammate@example.test', 'password' => 'unused']);
        $workspace = Workspace::query()->forceCreate([
            'owner_id' => $owner->getKey(),
            'name' => 'Monitor',
            'slug' => 'monitor',
            'plan' => 'pro',
            'stripe_subscription_id' => 'sub_test',
            'billing_status' => 'active',
        ]);
        $workspace->members()->attach($owner, ['role' => 'owner']);
        $workspace->members()->attach($teammate, ['role' => 'member']);
        $target = new ProductDeletionTarget('monitor', 'workspace', (string) $workspace->getKey(), (string) $owner->getKey(), '01JCOREWORKSPACE', '01JCOREACTOR');

        $preview = app(MonitorProductDeletionProvider::class)->inspect($target);

        $this->assertEqualsCanonicalizing(['workspace_has_other_members', 'billing_unsettled'], $preview->blockers);
        $this->assertSame(0, DB::connection('monitor')->table('product_deletion_fences')->count());
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->getKey()], 'monitor');
    }

    public function test_account_preflight_blocks_a_membership_outside_the_included_owned_workspace_set(): void
    {
        $owner = User::query()->forceCreate(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'unused']);
        $sharedOwner = User::query()->forceCreate(['name' => 'Shared owner', 'email' => 'shared@example.test', 'password' => 'unused']);
        $owned = Workspace::query()->forceCreate(['owner_id' => $owner->getKey(), 'name' => 'Owned', 'slug' => 'owned', 'plan' => 'free']);
        $shared = Workspace::query()->forceCreate(['owner_id' => $sharedOwner->getKey(), 'name' => 'Shared', 'slug' => 'shared', 'plan' => 'free']);
        $owned->members()->attach($owner, ['role' => 'owner']);
        $shared->members()->attach($sharedOwner, ['role' => 'owner']);
        $shared->members()->attach($owner, ['role' => 'member']);
        $target = new ProductDeletionTarget('monitor', 'account', (string) $owner->getKey(), (string) $owner->getKey(), '01JCOREACCOUNT', '01JCOREACTOR', [(string) $owned->getKey()]);

        $preview = app(MonitorProductDeletionProvider::class)->inspect($target);

        $this->assertContains('account_has_foreign_memberships', $preview->blockers);
    }

    public function test_pending_fenced_billing_event_blocks_preflight_until_core_acknowledges_it(): void
    {
        config(['monitor.beacon.plan_authority' => 'core']);
        $owner = User::query()->forceCreate(['name' => 'Owner', 'email' => 'billing-owner@example.test', 'password' => 'unused']);
        $workspace = Workspace::query()->forceCreate([
            'owner_id' => $owner->getKey(), 'name' => 'Settled Monitor', 'slug' => (string) Str::uuid(),
            'plan' => 'free', 'billing_status' => 'canceled',
        ]);
        $workspace->members()->attach($owner, ['role' => 'owner']);
        $target = new ProductDeletionTarget(
            'monitor', 'workspace', (string) $workspace->getKey(), (string) $owner->getKey(), '01JCOREWORKSPACE', '01JCOREACTOR',
        );
        DB::connection('monitor')->table('product_deletion_fences')->insert([
            'kind' => 'workspace', 'source_id' => (string) $workspace->getKey(),
            'request_id' => (string) Str::ulid(), 'step_id' => (string) Str::ulid(),
            'payload_hash' => hash('sha256', 'accepted-target'), 'target' => json_encode($target->toArray(), JSON_THROW_ON_ERROR),
            'status' => 'prepared', 'prepared_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $event = [
            'id' => 'evt_monitor_gap', 'type' => 'customer.subscription.created', 'created' => 1_800_000_000,
            'data' => ['object' => ['object' => 'subscription', 'id' => 'sub_monitor_gap', 'customer' => 'cus_monitor_gap',
                'status' => 'active', 'metadata' => ['workspace_id' => (string) $workspace->getKey(), 'plan' => 'pro']]],
        ];
        $coreBilling = \Mockery::mock(SyncMonitorBillingEventIntoCore::class);
        $coreBilling->shouldReceive('handle')->once()->with($event)->andReturnUsing(function () use ($target): bool {
            $sourceEvent = BillingEvent::query()->where('stripe_event_id', 'evt_monitor_gap')->firstOrFail();
            $this->assertSame(BillingEvent::STATUS_PENDING, $sourceEvent->processing_status);
            $this->assertNull($sourceEvent->processed_at);
            $preview = app(MonitorProductDeletionProvider::class)->inspect($target);
            $this->assertContains('billing_unsettled', $preview->blockers);

            return true;
        });
        $authority = new MonitorPlanAuthority(new LegacyIdentityResolver, \Mockery::mock(ProductPlanResolver::class));

        $result = (new ProcessStripeBillingEvent(app(StripeBillingClient::class), $authority, $coreBilling))->handle($event);

        $this->assertTrue($result);
        $sourceEvent = BillingEvent::query()->where('stripe_event_id', 'evt_monitor_gap')->firstOrFail();
        $this->assertSame(BillingEvent::STATUS_PENDING, $sourceEvent->processing_status);
        $this->assertNull($sourceEvent->processed_at);
        $this->assertSame('canceled', $workspace->fresh()->billing_status);
    }

    public function test_purge_rechecks_native_owner_and_billing_after_workspace_preparation(): void
    {
        [$provider, $target, $attempt, $workspace, $coreRequest, $step] = $this->workspaceAttempt();
        $this->assertSame('ready', $provider->prepare($attempt)->status);
        $this->advanceToPurge($coreRequest, $step);

        $newOwner = User::query()->forceCreate(['name' => 'New owner', 'email' => 'new-owner@example.test', 'password' => 'unused']);
        $workspace->members()->attach($newOwner, ['role' => 'owner']);
        $workspace->forceFill(['owner_id' => $newOwner->getKey()])->save();
        $attempt = $step->fresh()->attempt();
        $ownerResult = $provider->purge($attempt);
        $this->assertSame('blocked', $ownerResult->status);
        $this->assertSame('owner_changed', $ownerResult->reasonCode);

        $workspace->members()->detach($newOwner);
        $workspace->forceFill([
            'owner_id' => $target->actorSourceId,
            'billing_status' => 'active',
            'stripe_subscription_id' => 'sub_changed_after_prepare',
        ])->save();
        $billingResult = $provider->purge($attempt);
        $this->assertSame('blocked', $billingResult->status);
        $this->assertSame('billing_unsettled', $billingResult->reasonCode);
    }

    public function test_purge_replays_only_a_matching_completion_receipt_and_rejects_stale_attempts(): void
    {
        [$provider, $target, $attempt, $workspace, $coreRequest, $step] = $this->workspaceAttempt();
        $staleAttempt = new ProductDeletionAttempt(
            $attempt->requestId,
            $attempt->stepId,
            $attempt->target,
            $attempt->payloadHash,
            $attempt->generation,
            'stale-lease-token',
            'prepare',
        );

        try {
            $provider->prepare($staleAttempt);
            $this->fail('A stale attempt must not create a native fence.');
        } catch (DeletionBlocked $exception) {
            $this->assertSame('deletion_attempt_stale', $exception->reasonCode);
        }

        $this->assertSame('ready', $provider->prepare($attempt)->status);
        $this->advanceToPurge($coreRequest, $step);
        $purgeAttempt = $step->fresh()->attempt();
        $this->assertSame('completed', $provider->purge($purgeAttempt)->status);
        $this->assertSame('completed', $provider->purge($purgeAttempt)->status);
        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->getKey()], 'monitor');
        $this->assertDatabaseHas('product_deletion_receipts', [
            'kind' => 'workspace',
            'source_id' => (string) $target->sourceId,
            'request_id' => $purgeAttempt->requestId,
            'step_id' => $purgeAttempt->stepId,
            'payload_hash' => $purgeAttempt->payloadHash,
        ], 'monitor');
    }

    /** @return array{MonitorProductDeletionProvider, ProductDeletionTarget, ProductDeletionAttempt, Workspace, DeletionRequest, DeletionStep} */
    private function workspaceAttempt(): array
    {
        $owner = User::query()->forceCreate(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'unused']);
        $workspace = Workspace::query()->forceCreate([
            'owner_id' => $owner->getKey(), 'name' => 'Monitor', 'slug' => (string) Str::uuid(), 'plan' => 'free',
        ]);
        $workspace->members()->attach($owner, ['role' => 'owner']);
        $actor = PlatformUser::query()->create([
            'name' => 'Core owner', 'email' => 'core-owner@example.test', 'password' => 'unused', 'status' => 'active',
        ]);
        $coreWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $actor->getKey(), 'name' => 'Core workspace', 'slug' => (string) Str::uuid(), 'status' => 'deleting',
        ]);
        $target = new ProductDeletionTarget(
            'monitor', 'workspace', (string) $workspace->getKey(), (string) $owner->getKey(),
            (string) $coreWorkspace->getKey(), (string) $actor->getKey(),
        );
        $request = DeletionRequest::query()->create([
            'actor_id' => $actor->getKey(), 'kind' => 'workspace', 'target_id' => $coreWorkspace->getKey(),
            'workspace_ids' => [(string) $coreWorkspace->getKey()], 'identity_bindings' => [],
            'intent_hash' => hash('sha256', 'accepted-intent'), 'receipt_token_hash' => hash('sha256', 'receipt-token'),
            'idempotency_key' => (string) Str::uuid(), 'phase' => 'prepare', 'status' => 'pending', 'accepted_at' => now(),
        ]);
        $step = $request->steps()->create([
            'product' => 'monitor', 'kind' => 'workspace', 'source_id' => $target->sourceId, 'target' => $target->toArray(),
            'payload_hash' => hash('sha256', json_encode($target->toArray(), JSON_THROW_ON_ERROR)), 'phase' => 'prepare',
            'status' => 'processing', 'attempts' => 1, 'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(10),
        ]);

        return [app(MonitorProductDeletionProvider::class), $target, $step->attempt(), $workspace, $request, $step];
    }

    private function advanceToPurge(DeletionRequest $request, DeletionStep $step): void
    {
        $request->forceFill(['phase' => 'purge'])->save();
        $step->forceFill([
            'phase' => 'purge', 'status' => 'processing', 'attempts' => $step->attempts + 1,
            'lease_token' => Str::random(48), 'lease_expires_at' => now()->addMinutes(10),
        ])->save();
    }
}
