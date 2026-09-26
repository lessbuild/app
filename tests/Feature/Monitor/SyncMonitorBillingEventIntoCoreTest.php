<?php

namespace Tests\Feature\Monitor;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Models\ProductSubscription;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Monitor\Models\BillingEvent;
use App\Modules\Monitor\Models\Workspace as MonitorWorkspace;
use App\Modules\Monitor\Services\MonitorPlanAuthority;
use App\Modules\Monitor\Services\MonitorPlanSnapshot;
use App\Modules\Monitor\Services\ProcessStripeBillingEvent;
use App\Modules\Monitor\Services\ReconcileMonitorCoreBillingEvents;
use App\Modules\Monitor\Services\StripeBillingClient;
use App\Modules\Monitor\Services\SyncMonitorBillingEventIntoCore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SyncMonitorBillingEventIntoCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.beacon.plans' => [
            'free' => ['name' => 'Free', 'price' => 0, 'stripe_price_id' => null, 'apps' => 1, 'seats' => 1, 'event_limit' => 500_000],
            'pro' => ['name' => 'Pro', 'price' => 29, 'stripe_price_id' => 'price_monitor_pro', 'apps' => 'Unlimited', 'seats' => 5, 'event_limit' => 10_000_000, 'telemetry_guardrails' => true],
            'team' => ['name' => 'Team', 'price' => 99, 'stripe_price_id' => 'price_monitor_team', 'apps' => 'Unlimited', 'seats' => 15, 'event_limit' => 50_000_000],
        ]]);

        $this->dropTables();
        $this->createCoreTables();
        $this->createMonitorTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_active_subscription_projects_to_monitor_only_and_duplicate_events_are_idempotent(): void
    {
        $workspaceId = $this->addWorkspaceMapping(10);
        $deployerSubscriptionId = $this->addCurrentSubscription($workspaceId, 'deployer', 'deployer_legacy', 'deployer-sub-1', 'starter');
        $event = $this->event('evt_monitor_active', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(10));

        $this->assertTrue(app(SyncMonitorBillingEventIntoCore::class)->handle($event));

        $monitor = ProductSubscription::query()->where('product', 'monitor')->where('provider_subscription_id', 'sub_monitor_1')->firstOrFail();
        $assignment = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'monitor')->first();
        $billingEvent = DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_monitor_active')->first();

        $this->assertSame('stripe', $monitor->provider);
        $this->assertSame('monitor', $monitor->provider_account_key);
        $this->assertSame('pro', $monitor->plan_key);
        $this->assertSame('active', $monitor->status);
        $this->assertSame('sub_monitor_1', $monitor->provider_subscription_id);
        $this->assertSame(10_000_000, $monitor->metadata['plan_snapshot']['limits']['events_per_month']);
        $this->assertSame($monitor->getKey(), $assignment->product_subscription_id);
        $this->assertSame('applied', $billingEvent->processing_status);
        $resolution = app(MonitorPlanAuthority::class)->resolve(MonitorWorkspace::query()->findOrFail(10));
        $this->assertTrue($resolution->available);
        $this->assertSame('pro', $resolution->planKey);
        $this->assertSame($deployerSubscriptionId, DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'deployer')->value('product_subscription_id'));
        $this->assertFalse(app(SyncMonitorBillingEventIntoCore::class)->handle($event));
        $this->assertSame(1, DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_monitor_active')->count());
    }

    public function test_unknown_price_is_held_for_review_without_replacing_current_entitlements(): void
    {
        $workspaceId = $this->addWorkspaceMapping(20);
        BillingEvent::query()->create([
            'stripe_event_id' => 'evt_monitor_unknown_price',
            'event_type' => 'customer.subscription.updated',
            'workspace_id' => 20,
            'processing_status' => BillingEvent::STATUS_PENDING,
            'ignored_reason' => 'awaiting_core_reconciliation',
        ]);
        app(SyncMonitorBillingEventIntoCore::class)->handle(
            $this->event('evt_monitor_known', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(20)),
        );

        $unknownPrice = $this->subscriptionObject(20);
        $unknownPrice['items']['data'][0]['price']['id'] = 'price_not_configured';
        $unknownPrice['metadata']['plan'] = 'pro';
        $unknownPrice['items']['data'][0]['quantity'] = 2;
        app(SyncMonitorBillingEventIntoCore::class)->handle(
            $this->event('evt_monitor_unknown_price', 'customer.subscription.updated', 1_800_000_100, $unknownPrice),
        );

        $subscription = ProductSubscription::query()->where('provider_subscription_id', 'sub_monitor_1')->firstOrFail();
        $currentId = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'monitor')->value('product_subscription_id');
        $billingEvent = DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_monitor_unknown_price')->first();

        $this->assertSame('pro', $subscription->plan_key);
        $this->assertSame('price_monitor_pro', $subscription->provider_price_id);
        $this->assertSame(1, $subscription->quantity);
        $this->assertSame($subscription->getKey(), $currentId);
        $this->assertSame('needs_review', $billingEvent->processing_status);
        $this->assertSame('plan_or_subscription_unrecognized', $billingEvent->ignored_reason);
        $sourceEvent = BillingEvent::query()->where('stripe_event_id', 'evt_monitor_unknown_price')->firstOrFail();
        $this->assertSame(BillingEvent::STATUS_PENDING, $sourceEvent->processing_status);
        $this->assertNull($sourceEvent->processed_at);
    }

    public function test_reconciliation_acknowledges_a_source_receipt_after_a_crash_following_the_core_commit(): void
    {
        $this->addWorkspaceMapping(21);
        $event = $this->event('evt_lost_source_ack', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(21));
        app(SyncMonitorBillingEventIntoCore::class)->handle($event);
        $source = BillingEvent::query()->create([
            'stripe_event_id' => $event['id'], 'event_type' => $event['type'], 'workspace_id' => 21,
            'processing_status' => BillingEvent::STATUS_PENDING, 'ignored_reason' => 'awaiting_core_reconciliation',
        ]);
        $source->forceFill(['updated_at' => now()->subHour()])->save();
        Http::preventStrayRequests();

        $summary = app(ReconcileMonitorCoreBillingEvents::class)->handle();

        $this->assertSame(1, $summary['completed']);
        $this->assertSame(BillingEvent::STATUS_APPLIED, $source->refresh()->processing_status);
        $this->assertNotNull($source->processed_at);
        Http::assertNothingSent();
    }

    public function test_reconciliation_recovers_a_native_event_that_never_reached_core(): void
    {
        config(['monitor.beacon.billing.stripe.secret' => 'sk_test_fixture']);
        $this->addWorkspaceMapping(22);
        $event = $this->event('evt_source_before_core', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(22));
        $source = BillingEvent::query()->create([
            'stripe_event_id' => $event['id'], 'event_type' => $event['type'], 'workspace_id' => 22,
            'stripe_created_at' => $event['created'], 'processing_status' => BillingEvent::STATUS_PENDING,
            'ignored_reason' => 'awaiting_core_reconciliation',
        ]);
        $source->forceFill(['updated_at' => now()->subHour()])->save();
        Http::fake(['https://api.stripe.com/v1/events/evt_source_before_core' => Http::response($event, 200)]);

        $summary = app(ReconcileMonitorCoreBillingEvents::class)->handle();

        $this->assertSame(1, $summary['completed']);
        $this->assertSame(BillingEvent::STATUS_APPLIED, $source->refresh()->processing_status);
        $this->assertNotNull($source->processed_at);
        $this->assertSame('active', ProductSubscription::query()->where('provider_subscription_id', 'sub_monitor_1')->value('status'));
    }

    public function test_late_cancellation_for_historical_subscription_does_not_remove_a_newer_current_plan(): void
    {
        $workspaceId = $this->addWorkspaceMapping(30);
        $oldSubscription = $this->addProductSubscription($workspaceId, 'monitor', 'stripe', 'monitor', 'sub_monitor_old', 'pro', 'active', [
            'plan_snapshot' => $this->snapshot('pro'),
            'billing_state' => ['event_position' => ['created_at' => 1_800_000_000, 'event_id' => 'evt_old_active']],
        ]);
        $currentSubscription = $this->addProductSubscription($workspaceId, 'monitor', 'stripe', 'monitor', 'sub_monitor_new', 'team', 'active', [
            'plan_snapshot' => $this->snapshot('team'),
            'billing_state' => ['event_position' => ['created_at' => 1_800_000_200, 'event_id' => 'evt_new_active']],
        ]);
        $this->assignCurrentSubscription($workspaceId, 'monitor', $currentSubscription->getKey());
        $object = $this->subscriptionObject(30, 'sub_monitor_old', 'price_monitor_pro');
        $object['status'] = 'canceled';
        $object['canceled_at'] = 1_800_000_300;

        app(SyncMonitorBillingEventIntoCore::class)->handle(
            $this->event('evt_old_canceled', 'customer.subscription.deleted', 1_800_000_300, $object),
        );

        $this->assertSame('canceled', $oldSubscription->fresh()->status);
        $this->assertSame($currentSubscription->getKey(), DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'monitor')->value('product_subscription_id'));
        $this->assertSame('team', ProductSubscription::query()->findOrFail($currentSubscription->getKey())->plan_key);
    }

    public function test_cancellation_preserves_paid_history_and_assigns_the_monitor_free_plan(): void
    {
        $workspaceId = $this->addWorkspaceMapping(40);
        app(SyncMonitorBillingEventIntoCore::class)->handle(
            $this->event('evt_monitor_created', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(40)),
        );
        $paid = ProductSubscription::query()->where('provider_subscription_id', 'sub_monitor_1')->firstOrFail();
        $deleted = $this->subscriptionObject(40);
        $deleted['status'] = 'canceled';
        $deleted['canceled_at'] = 1_800_000_100;

        app(SyncMonitorBillingEventIntoCore::class)->handle(
            $this->event('evt_monitor_deleted', 'customer.subscription.deleted', 1_800_000_100, $deleted),
        );

        $currentId = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'monitor')->value('product_subscription_id');
        $current = ProductSubscription::query()->findOrFail($currentId);

        $this->assertSame('canceled', $paid->fresh()->status);
        $this->assertSame('pro', $paid->fresh()->plan_key);
        $this->assertSame('monitor_legacy', $current->provider);
        $this->assertSame('free', $current->plan_key);
        $this->assertSame('active', $current->status);
    }

    public function test_unmapped_workspace_event_is_held_without_granting_a_plan(): void
    {
        $event = $this->event('evt_monitor_unmapped', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(999));

        app(SyncMonitorBillingEventIntoCore::class)->handle($event);

        $billingEvent = DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_monitor_unmapped')->first();

        $this->assertSame('pending_reconciliation', $billingEvent->processing_status);
        $this->assertSame('workspace_mapping_missing', $billingEvent->ignored_reason);
        $this->assertNull($billingEvent->workspace_id);
        $this->assertSame(0, DB::connection('core')->table('current_product_subscriptions')->count());
        $this->assertSame(0, DB::connection('core')->table('product_subscriptions')->count());
    }

    public function test_pending_event_can_be_replayed_after_workspace_mapping_is_reconciled(): void
    {
        $event = $this->event('evt_monitor_reconcile', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(60));
        app(SyncMonitorBillingEventIntoCore::class)->handle($event);

        config([
            'monitor.beacon.billing.stripe.secret' => 'sk_test_monitor',
            'monitor.beacon.billing.stripe.api_url' => 'https://api.stripe.com',
        ]);
        Http::fake([
            'https://api.stripe.com/v1/events/evt_monitor_reconcile' => Http::response($event, 200),
        ]);

        $firstRetry = app(ReconcileMonitorCoreBillingEvents::class)->handle(eventId: 'evt_monitor_reconcile');
        $this->assertSame(1, $firstRetry['pending']);

        $billingEvent = DB::connection('core')->table('product_billing_events')
            ->where('provider_event_id', 'evt_monitor_reconcile')->first();
        $this->assertSame(1, json_decode($billingEvent->metadata, true)['reconciliation_attempts']);

        $stillPending = app(ReconcileMonitorCoreBillingEvents::class)->handle(eventId: 'evt_monitor_reconcile');
        $this->assertSame(1, $stillPending['pending']);
        $billingEvent = DB::connection('core')->table('product_billing_events')
            ->where('provider_event_id', 'evt_monitor_reconcile')->first();
        $this->assertSame(2, json_decode($billingEvent->metadata, true)['reconciliation_attempts']);

        $this->addWorkspaceMapping(60);
        $resolvedRetry = app(ReconcileMonitorCoreBillingEvents::class)->handle(eventId: 'evt_monitor_reconcile');
        $this->assertSame(1, $resolvedRetry['completed']);

        $billingEvent = DB::connection('core')->table('product_billing_events')
            ->where('provider_event_id', 'evt_monitor_reconcile')->first();

        $this->assertSame('applied', $billingEvent->processing_status);
        $this->assertSame('pro', ProductSubscription::query()->where('provider_subscription_id', 'sub_monitor_1')->value('plan_key'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/events/evt_monitor_reconcile');
    }

    public function test_source_ignored_event_remains_ignored_in_core(): void
    {
        $this->addWorkspaceMapping(50);
        BillingEvent::query()->create([
            'stripe_event_id' => 'evt_monitor_ignored',
            'event_type' => 'customer.subscription.updated',
            'workspace_id' => 50,
            'stripe_created_at' => 1_800_000_000,
            'processing_status' => BillingEvent::STATUS_IGNORED,
            'ignored_reason' => 'stale_event',
            'processed_at' => now(),
        ]);

        app(SyncMonitorBillingEventIntoCore::class)->handle(
            $this->event('evt_monitor_ignored', 'customer.subscription.updated', 1_800_000_000, $this->subscriptionObject(50)),
        );

        $billingEvent = DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_monitor_ignored')->first();

        $this->assertSame('ignored', $billingEvent->processing_status);
        $this->assertSame('stale_event', $billingEvent->ignored_reason);
        $this->assertSame(0, DB::connection('core')->table('product_subscriptions')->count());
    }

    public function test_billing_event_ignored_during_workspace_deletion_is_reconciled_into_core(): void
    {
        $workspaceId = $this->addWorkspaceMapping(70);
        DB::connection('core')->table('workspaces')->where('id', $workspaceId)->update(['status' => 'deleting']);
        BillingEvent::query()->create([
            'stripe_event_id' => 'evt_monitor_deleting',
            'event_type' => 'customer.subscription.created',
            'workspace_id' => 70,
            'stripe_created_at' => 1_800_000_000,
            'processing_status' => BillingEvent::STATUS_PENDING,
            'ignored_reason' => 'awaiting_core_reconciliation',
            'processed_at' => null,
        ]);

        app(SyncMonitorBillingEventIntoCore::class)->handle(
            $this->event('evt_monitor_deleting', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(70)),
        );

        $billingEvent = DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_monitor_deleting')->first();
        $subscription = ProductSubscription::query()->where('provider_subscription_id', 'sub_monitor_1')->firstOrFail();

        $this->assertSame('applied', $billingEvent->processing_status);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('deleting', DB::connection('core')->table('workspaces')->where('id', $workspaceId)->value('status'));
        $this->assertSame($subscription->getKey(), DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'monitor')->value('product_subscription_id'));
        $sourceEvent = BillingEvent::query()->where('stripe_event_id', 'evt_monitor_deleting')->firstOrFail();
        $this->assertSame(BillingEvent::STATUS_APPLIED, $sourceEvent->processing_status);
        $this->assertNotNull($sourceEvent->processed_at);
    }

    public function test_billing_event_for_deleted_workspace_is_deduplicated_without_recreating_subscription(): void
    {
        $workspaceId = $this->addWorkspaceMapping(80);
        DB::connection('core')->table('workspaces')->where('id', $workspaceId)->update(['status' => 'deleted']);
        DB::connection('core')->table('legacy_identity_maps')->where('source_product', 'monitor')
            ->where('source_entity', 'workspace')->where('source_id', '80')->update(['status' => 'deleted']);
        BillingEvent::query()->create([
            'stripe_event_id' => 'evt_monitor_deleted_workspace',
            'event_type' => 'customer.subscription.created',
            'workspace_id' => 80,
            'stripe_created_at' => 1_800_000_000,
            'processing_status' => BillingEvent::STATUS_PENDING,
            'ignored_reason' => 'awaiting_core_reconciliation',
            'processed_at' => null,
        ]);

        app(SyncMonitorBillingEventIntoCore::class)->handle(
            $this->event('evt_monitor_deleted_workspace', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(80)),
        );

        $billingEvent = DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_monitor_deleted_workspace')->first();

        $this->assertSame('ignored', $billingEvent->processing_status);
        $this->assertSame('workspace_deleted', $billingEvent->ignored_reason);
        $this->assertSame(0, DB::connection('core')->table('product_subscriptions')->count());
        $this->assertSame(0, DB::connection('core')->table('current_product_subscriptions')->count());
        $this->assertSame('deleted', DB::connection('core')->table('workspaces')->where('id', $workspaceId)->value('status'));
        $sourceEvent = BillingEvent::query()->where('stripe_event_id', 'evt_monitor_deleted_workspace')->firstOrFail();
        $this->assertSame(BillingEvent::STATUS_IGNORED, $sourceEvent->processing_status);
        $this->assertNotNull($sourceEvent->processed_at);
    }

    public function test_verified_stripe_event_during_native_deletion_is_still_forwarded_to_core(): void
    {
        config(['monitor.beacon.plan_authority' => 'core']);
        $this->addWorkspaceMapping(90);
        DB::connection('monitor')->table('product_deletion_fences')->insert([
            'kind' => 'workspace',
            'source_id' => '90',
            'request_id' => (string) Str::ulid(),
            'step_id' => (string) Str::ulid(),
            'payload_hash' => hash('sha256', 'target'),
            'target' => '{}',
            'status' => 'prepared',
            'prepared_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $event = $this->event('evt_monitor_native_deleting', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(90));
        $coreBilling = \Mockery::mock(SyncMonitorBillingEventIntoCore::class);
        $coreBilling->shouldReceive('handle')->once()->with($event)->andReturn(true);
        $authority = new MonitorPlanAuthority(
            new LegacyIdentityResolver,
            \Mockery::mock(ProductPlanResolver::class),
        );
        $processor = new ProcessStripeBillingEvent(app(StripeBillingClient::class), $authority, $coreBilling);

        $this->assertTrue($processor->handle($event));
        $stored = BillingEvent::query()->where('stripe_event_id', 'evt_monitor_native_deleting')->firstOrFail();
        $this->assertSame(90, (int) $stored->workspace_id);
        $this->assertSame(BillingEvent::STATUS_PENDING, $stored->processing_status);
        $this->assertSame('awaiting_core_reconciliation', $stored->ignored_reason);
        $this->assertNull($stored->processed_at);
        $this->assertSame('Monitor workspace 90', DB::connection('monitor')->table('workspaces')->where('id', 90)->value('name'));
    }

    private function dropTables(): void
    {
        Schema::connection('monitor')->dropIfExists('product_deletion_fences');
        Schema::connection('monitor')->dropIfExists('billing_events');
        Schema::connection('monitor')->dropIfExists('workspaces');

        foreach ([
            'product_billing_events', 'current_product_subscriptions', 'product_subscriptions',
            'billing_customers', 'legacy_identity_maps', 'workspaces',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }
    }

    private function createCoreTables(): void
    {
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->string('status', 24)->default('active');
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('source_product', 24);
            $table->string('source_entity', 100);
            $table->string('source_id', 191);
            $table->string('canonical_entity', 100)->nullable();
            $table->string('canonical_id', 26)->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('batch_key', 100)->nullable();
            $table->text('reconciliation_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['source_product', 'source_entity', 'source_id']);
        });
        Schema::connection('core')->create('billing_customers', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26)->nullable();
            $table->string('provider', 40);
            $table->string('provider_account_key', 100)->default('default');
            $table->string('provider_customer_id', 191);
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_account_key', 'provider_customer_id']);
        });
        Schema::connection('core')->create('product_subscriptions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('billing_customer_id', 26)->nullable();
            $table->string('product', 24);
            $table->string('provider', 40);
            $table->string('provider_account_key', 100)->default('default');
            $table->string('provider_subscription_id', 191)->nullable();
            $table->string('provider_price_id', 191)->nullable();
            $table->string('plan_key', 100)->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('current_product_subscriptions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('product', 24);
            $table->char('product_subscription_id', 26);
            $table->timestamps();
            $table->unique(['workspace_id', 'product']);
        });
        Schema::connection('core')->create('product_billing_events', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26)->nullable();
            $table->string('product', 24);
            $table->string('provider', 40);
            $table->string('provider_account_key', 100)->default('default');
            $table->string('provider_event_id', 191);
            $table->string('event_type', 191);
            $table->unsignedBigInteger('provider_created_at')->nullable();
            $table->string('processing_status', 32)->default('applied');
            $table->string('ignored_reason', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_account_key', 'provider_event_id']);
        });
    }

    private function createMonitorTables(): void
    {
        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('billing_events', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('event_type');
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('stripe_created_at')->nullable();
            $table->string('processing_status', 16)->default('applied');
            $table->string('ignored_reason', 64)->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('product_deletion_fences', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24);
            $table->string('source_id', 191);
            $table->string('request_id', 26);
            $table->string('step_id', 26);
            $table->char('payload_hash', 64);
            $table->text('target');
            $table->string('status', 24);
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    private function addWorkspaceMapping(int $sourceWorkspaceId): string
    {
        $workspaceId = (string) Str::ulid();
        DB::connection('monitor')->table('workspaces')->insert([
            'id' => $sourceWorkspaceId,
            'name' => 'Monitor workspace '.$sourceWorkspaceId,
            'slug' => 'monitor-'.$sourceWorkspaceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspaces')->insert([
            'id' => $workspaceId,
            'name' => 'Monitor workspace '.$sourceWorkspaceId,
            'slug' => 'monitor-'.$sourceWorkspaceId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'monitor',
            'source_entity' => 'workspace',
            'source_id' => (string) $sourceWorkspaceId,
            'canonical_entity' => 'workspace',
            'canonical_id' => $workspaceId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $workspaceId;
    }

    /** @param array<string, mixed> $metadata */
    private function addProductSubscription(
        string $workspaceId,
        string $product,
        string $provider,
        string $providerAccount,
        ?string $providerSubscriptionId,
        string $plan,
        string $status,
        array $metadata = [],
    ): ProductSubscription {
        return ProductSubscription::query()->create([
            'workspace_id' => $workspaceId,
            'product' => $product,
            'provider' => $provider,
            'provider_account_key' => $providerAccount,
            'provider_subscription_id' => $providerSubscriptionId,
            'provider_price_id' => $product === 'monitor' && $plan !== 'free' ? 'price_monitor_'.$plan : null,
            'plan_key' => $plan,
            'status' => $status,
            'quantity' => 1,
            'metadata' => $metadata,
        ]);
    }

    private function addCurrentSubscription(string $workspaceId, string $product, string $provider, string $providerSubscriptionId, string $plan): string
    {
        $subscription = $this->addProductSubscription($workspaceId, $product, $provider, $product, $providerSubscriptionId, $plan, 'active');
        $this->assignCurrentSubscription($workspaceId, $product, $subscription->getKey());

        return $subscription->getKey();
    }

    private function assignCurrentSubscription(string $workspaceId, string $product, string $subscriptionId): void
    {
        DB::connection('core')->table('current_product_subscriptions')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspaceId,
            'product' => $product,
            'product_subscription_id' => $subscriptionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function subscriptionObject(int $sourceWorkspaceId, string $subscriptionId = 'sub_monitor_1', string $priceId = 'price_monitor_pro'): array
    {
        return [
            'object' => 'subscription',
            'id' => $subscriptionId,
            'customer' => 'cus_monitor_'.$sourceWorkspaceId,
            'status' => 'active',
            'metadata' => ['workspace_id' => (string) $sourceWorkspaceId, 'plan' => 'pro'],
            'items' => ['data' => [[
                'quantity' => 1,
                'price' => ['id' => $priceId],
            ]]],
            'current_period_start' => 1_800_000_000,
            'current_period_end' => 1_802_592_000,
        ];
    }

    /** @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    private function event(string $id, string $type, int $created, array $object): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'created' => $created,
            'data' => ['object' => $object],
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(string $plan): array
    {
        return app(MonitorPlanSnapshot::class)->forPlan($plan) ?? [];
    }
}
