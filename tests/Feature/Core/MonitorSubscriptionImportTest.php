<?php

namespace Tests\Feature\Core;

use App\Core\Services\Migration\ImportMonitorSubscriptionsIntoCore;
use App\Modules\Monitor\Models\Workspace as MonitorWorkspace;
use App\Modules\Monitor\Services\MonitorPlanAuthority;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MonitorSubscriptionImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.beacon.plans' => [
            'free' => ['stripe_price_id' => null, 'price' => 0],
            'pro' => ['stripe_price_id' => 'price_monitor_pro', 'price' => 49],
            'team' => ['stripe_price_id' => 'price_monitor_team', 'price' => 99],
        ]]);

        $this->createCoreTables();
        $this->createMonitorTables();
    }

    protected function tearDown(): void
    {
        foreach (['billing_events', 'workspaces'] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        foreach ([
            'product_billing_events', 'current_product_subscriptions', 'product_subscriptions',
            'billing_customers', 'legacy_identity_maps', 'workspaces',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_preview_and_apply_preserve_monitor_subscription_scope_and_billing_event_history(): void
    {
        $workspaceId = $this->addCoreWorkspaceForMonitor(10);
        $this->addMonitorWorkspace(10, [
            'plan' => 'pro',
            'stripe_customer_id' => 'cus_shared',
            'stripe_subscription_id' => 'sub_shared',
            'stripe_price_id' => 'price_monitor_pro',
            'billing_status' => 'active',
            'billing_period_ends_at' => '2026-12-23 00:00:00',
            'billing_cancel_at_period_end' => false,
            'billing_updated_at' => '2026-09-20 12:00:00',
            'billing_event_created_at' => 1790000000,
            'billing_event_id' => 'evt_latest',
            'stripe_checkout_session_id' => 'cs_pending',
            'stripe_checkout_url' => 'https://checkout.example.test/one-time-token',
            'billing_checkout_plan' => 'team',
            'billing_checkout_started_at' => '2026-09-22 10:00:00',
        ]);
        $this->addBillingEvent(1, 'evt_latest', 10, 'customer.subscription.updated', 1790000000, 'applied');
        $this->addBillingEvent(2, 'evt_orphan', 999, 'customer.subscription.deleted', 1789000000, 'ignored', 'workspace_not_found');
        $this->addOtherProductBillingRecords();

        $importer = app(ImportMonitorSubscriptionsIntoCore::class);
        $preview = $importer->run();

        $this->assertSame(1, $preview['subscriptions_ready']);
        $this->assertSame(2, $preview['billing_events_ready']);
        $this->assertSame(0, $preview['subscriptions_imported']);
        $this->assertSame(0, $preview['billing_events_imported']);
        $this->assertSame(1, DB::connection('core')->table('product_subscriptions')->count());
        $this->assertSame(0, DB::connection('core')->table('product_billing_events')->count());

        $applied = $importer->run(apply: true);
        $subscription = DB::connection('core')->table('product_subscriptions')
            ->where('provider_account_key', 'monitor')->where('provider_subscription_id', 'sub_shared')->first();
        $subscriptionMetadata = json_decode($subscription->metadata, true, 512, JSON_THROW_ON_ERROR);
        $current = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'monitor')->first();
        $mappedEvent = DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_latest')->first();
        $orphanEvent = DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_orphan')->first();

        $this->assertSame(1, $applied['subscriptions_imported']);
        $this->assertSame(1, $applied['stripe_subscriptions_imported']);
        $this->assertSame(1, $applied['billing_customers_imported']);
        $this->assertSame(2, $applied['billing_events_imported']);
        $this->assertSame('monitor', $subscription->product);
        $this->assertSame('monitor', $subscription->provider_account_key);
        $this->assertSame('pro', $subscription->plan_key);
        $this->assertSame('stripe', $subscription->provider);
        $this->assertSame('pro', $subscriptionMetadata['billing_state']['source_plan']);
        $this->assertSame('cs_pending', $subscriptionMetadata['billing_state']['pending_checkout']['session_id']);
        $this->assertFalse($subscriptionMetadata['billing_state']['pending_checkout']['checkout_url_copied']);
        $this->assertStringNotContainsString('one-time-token', $subscription->metadata);
        $this->assertSame($subscription->id, $current->product_subscription_id);
        $this->assertSame($workspaceId, $mappedEvent->workspace_id);
        $this->assertSame('ignored', $orphanEvent->processing_status);
        $this->assertNull($orphanEvent->workspace_id);
        $this->assertSame('monitor', $orphanEvent->product);
        $this->assertSame('workspace_not_found', $orphanEvent->ignored_reason);

        $orphanMetadata = json_decode($orphanEvent->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('999', $orphanMetadata['source_workspace_id']);
        $this->assertSame('unmapped', $orphanMetadata['workspace_mapping_status']);

        $secondRun = $importer->run(apply: true);
        $this->assertSame(1, $secondRun['subscriptions_already_mapped']);
        $this->assertSame(2, $secondRun['billing_events_already_mapped']);
        $this->assertSame(2, DB::connection('core')->table('product_billing_events')->count());
        $this->assertSame(2, DB::connection('core')->table('product_subscriptions')->count());
        $this->assertSame('cus_shared', DB::connection('core')->table('billing_customers')
            ->where('provider_account_key', 'monitor')->value('provider_customer_id'));
    }

    public function test_canceled_stripe_subscription_is_historical_while_the_free_plan_remains_current(): void
    {
        $workspaceId = $this->addCoreWorkspaceForMonitor(20);
        $this->addMonitorWorkspace(20, [
            'plan' => 'free',
            'stripe_customer_id' => 'cus_canceled',
            'stripe_subscription_id' => 'sub_canceled',
            'stripe_price_id' => 'price_monitor_pro',
            'billing_status' => 'canceled',
            'billing_period_ends_at' => '2026-09-01 00:00:00',
            'billing_updated_at' => '2026-09-01 00:01:00',
        ]);

        $report = app(ImportMonitorSubscriptionsIntoCore::class)->run(apply: true);
        $stripeSubscription = DB::connection('core')->table('product_subscriptions')
            ->where('provider_subscription_id', 'sub_canceled')->first();
        $currentAssignment = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'monitor')->first();
        $currentSubscription = DB::connection('core')->table('product_subscriptions')
            ->where('id', $currentAssignment->product_subscription_id)->first();

        $this->assertSame(1, $report['subscriptions_imported']);
        $this->assertSame('canceled', $stripeSubscription->status);
        $this->assertSame('pro', $stripeSubscription->plan_key);
        $this->assertSame('monitor_legacy', $currentSubscription->provider);
        $this->assertSame('free', $currentSubscription->plan_key);
        $this->assertSame('active', $currentSubscription->status);
        $this->assertNull($currentSubscription->provider_subscription_id);
        $this->assertSame(2, DB::connection('core')->table('product_subscriptions')->where('workspace_id', $workspaceId)->count());
    }

    public function test_monitor_import_normalizes_core_entitlements_and_limits_while_preserving_legacy_snapshot(): void
    {
        config(['monitor.beacon.plans' => [
            'pro' => [
                'name' => 'Pro',
                'price' => 49,
                'apps' => 'Unlimited',
                'seats' => 5,
                'dashboards' => 4,
                'escalation_steps' => 3,
                'deployment_context_minutes' => 60,
                'event_limit' => 10_000_000,
                'retention_days' => 30,
                'telemetry_guardrails' => true,
                'slo_burn_rate' => true,
                'slo_burn_rate_alerts' => true,
                'slo_reports' => false,
                'anomaly_detection' => true,
                'log_pattern_alerts' => true,
                'audit_log' => true,
                'issue_digest' => true,
                'features' => ['Original Monitor catalog copy'],
            ],
        ]]);
        config(['monitor.beacon.plan_authority' => 'core']);
        $workspaceId = $this->addCoreWorkspaceForMonitor(40);
        $this->addMonitorWorkspace(40, ['plan' => 'pro', 'billing_status' => 'active']);

        $report = app(ImportMonitorSubscriptionsIntoCore::class)->run(apply: true);
        $subscription = DB::connection('core')->table('product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'monitor')->first();
        $metadata = json_decode($subscription->metadata, true, 512, JSON_THROW_ON_ERROR);
        $snapshot = $metadata['plan_snapshot'];
        $resolution = app(MonitorPlanAuthority::class)->resolve(MonitorWorkspace::query()->findOrFail(40));

        $this->assertSame(1, $report['subscriptions_imported']);
        $this->assertSame(['telemetry_guardrails', 'slo_burn_rate', 'slo_burn_rate_alerts', 'anomaly_detection', 'log_pattern_alerts', 'audit_log', 'issue_digest'], $snapshot['entitlements']);
        $this->assertSame([
            'applications' => null,
            'seats' => 5,
            'dashboards' => 4,
            'escalation_steps' => 3,
            'deployment_context_minutes' => 60,
            'events_per_month' => 10_000_000,
            'retention_days' => 30,
        ], $snapshot['limits']);
        $this->assertSame(['Original Monitor catalog copy'], $snapshot['features']);
        $this->assertSame(10_000_000, $metadata['billing_state']['plan_snapshot']['event_limit']);
        $this->assertTrue($resolution->available);
        $this->assertTrue($resolution->allows('telemetry_guardrails'));
        $this->assertFalse($resolution->allows('slo_reports'));
        $this->assertNull($resolution->limit('applications'));
        $this->assertSame(10_000_000, $resolution->limit('events_per_month'));
    }

    public function test_unknown_plan_is_held_for_review_then_retried_after_source_correction(): void
    {
        $this->addCoreWorkspaceForMonitor(30);
        $this->addMonitorWorkspace(30, ['plan' => 'enterprise_custom']);
        $importer = app(ImportMonitorSubscriptionsIntoCore::class);

        $firstRun = $importer->run(apply: true);
        $reviewMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')->where('source_entity', 'current_subscription')->where('source_id', '30')->first();
        $reviewMetadata = json_decode($reviewMap->metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $firstRun['subscriptions_blocked']);
        $this->assertSame('needs_review', $reviewMap->status);
        $this->assertContains('unknown_monitor_plan', $reviewMetadata['reason_codes']);
        $this->assertSame(0, DB::connection('core')->table('product_subscriptions')->count());

        DB::connection('monitor')->table('workspaces')->where('id', 30)->update(['plan' => 'pro']);
        $retry = $importer->run(apply: true);
        $resolvedMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')->where('source_entity', 'current_subscription')->where('source_id', '30')->first();
        $resolvedMetadata = json_decode($resolvedMap->metadata, true, 512, JSON_THROW_ON_ERROR);
        $workspaceId = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'monitor')->where('source_entity', 'workspace')->where('source_id', '30')->value('canonical_id');

        $this->assertSame(1, $retry['subscriptions_imported']);
        $this->assertSame('reconciled', $resolvedMap->status);
        $this->assertContains('unknown_monitor_plan', $resolvedMetadata['review_history'][0]['reason_codes']);
        $this->assertSame('pro', DB::connection('core')->table('product_subscriptions')
            ->where('workspace_id', $workspaceId)->value('plan_key'));
    }

    private function createCoreTables(): void
    {
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26)->nullable();
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
            $table->string('plan')->default('free');
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->string('stripe_price_id')->nullable();
            $table->string('billing_status')->default('inactive');
            $table->timestamp('billing_period_ends_at')->nullable();
            $table->boolean('billing_cancel_at_period_end')->default(false);
            $table->timestamp('billing_updated_at')->nullable();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->text('stripe_checkout_url')->nullable();
            $table->string('billing_checkout_plan', 32)->nullable();
            $table->timestamp('billing_checkout_started_at')->nullable();
            $table->unsignedBigInteger('billing_event_created_at')->nullable();
            $table->string('billing_event_id')->nullable();
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
    }

    private function addCoreWorkspaceForMonitor(int $sourceWorkspaceId): string
    {
        $workspaceId = (string) Str::ulid();
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

    /** @param array<string, mixed> $attributes */
    private function addMonitorWorkspace(int $id, array $attributes): void
    {
        DB::connection('monitor')->table('workspaces')->insert(array_merge([
            'id' => $id,
            'plan' => 'free',
            'billing_status' => 'inactive',
            'billing_cancel_at_period_end' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function addBillingEvent(
        int $id,
        string $eventId,
        int $workspaceId,
        string $eventType,
        int $createdAt,
        string $status,
        ?string $ignoredReason = null,
    ): void {
        DB::connection('monitor')->table('billing_events')->insert([
            'id' => $id,
            'stripe_event_id' => $eventId,
            'event_type' => $eventType,
            'workspace_id' => $workspaceId,
            'processed_at' => now(),
            'stripe_created_at' => $createdAt,
            'processing_status' => $status,
            'ignored_reason' => $ignoredReason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addOtherProductBillingRecords(): void
    {
        DB::connection('core')->table('billing_customers')->insert([
            'id' => (string) Str::ulid(),
            'provider' => 'stripe',
            'provider_account_key' => 'analytics',
            'provider_customer_id' => 'cus_shared',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('product_subscriptions')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => (string) Str::ulid(),
            'product' => 'analytics',
            'provider' => 'stripe',
            'provider_account_key' => 'analytics',
            'provider_subscription_id' => 'sub_shared',
            'plan_key' => 'pro',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
