<?php

namespace Tests\Feature\Core;

use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace;
use App\Core\Services\Migration\InitializeAnalyticsPlanSlotsInCore;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Analytics\Services\AnalyticsPlanAuthority;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AnalyticsPlanSlotInitializationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCoreTables();
        $this->createAnalyticsTables();
    }

    protected function tearDown(): void
    {
        Schema::connection('analytics')->dropIfExists('workspaces');

        foreach (['current_product_subscriptions', 'product_subscriptions', 'legacy_identity_maps', 'workspaces'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_command_preview_is_read_only_and_apply_initializes_an_idempotent_unlimited_baseline(): void
    {
        $workspaceId = $this->addReconciledAnalyticsWorkspace(41);

        $this->artisan('platform:initialize-analytics-plan-slots')
            ->expectsOutputToContain('Read-only Analytics plan-slot initialization preview.')
            ->expectsOutputToContain('No data was changed.')
            ->assertSuccessful();

        $this->assertDatabaseCount('product_subscriptions', 0, 'core');
        $this->assertDatabaseCount('current_product_subscriptions', 0, 'core');
        $this->assertDatabaseMissing('legacy_identity_maps', [
            'source_product' => 'analytics',
            'source_entity' => 'current_subscription',
            'source_id' => '41',
        ], 'core');

        $firstRun = app(InitializeAnalyticsPlanSlotsInCore::class)->run(apply: true);
        $current = CurrentProductSubscription::query()
            ->where('workspace_id', $workspaceId)
            ->where('product', 'analytics')
            ->firstOrFail();
        $subscription = $current->subscription;
        $mapping = LegacyIdentityMap::query()
            ->where('source_product', 'analytics')
            ->where('source_entity', 'current_subscription')
            ->where('source_id', '41')
            ->firstOrFail();

        $this->assertSame(1, $firstRun['plan_slots_initialized']);
        $this->assertSame('legacy_access', $subscription->plan_key);
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->billing_customer_id);
        $this->assertNull($subscription->provider_subscription_id);
        $this->assertSame(['*'], $subscription->metadata['plan_snapshot']['entitlements']);
        $this->assertSame([
            'sites' => null,
            'members' => null,
            'events_per_month' => null,
            'retention_days' => 90,
            'aggregate_retention_months' => 13,
            'export_retention_hours' => 24,
        ], $subscription->metadata['plan_snapshot']['limits']);
        $this->assertSame('reconciled', $mapping->status);
        $this->assertSame('current_product_subscription', $mapping->canonical_entity);

        config([
            'analytics.plan_authority' => 'core',
            'platform.billing.entitled_statuses.analytics' => ['active', 'trialing'],
        ]);
        $resolution = app(AnalyticsPlanAuthority::class)->resolve(AnalyticsWorkspace::query()->findOrFail(41));

        $this->assertTrue($resolution->available);
        $this->assertTrue($resolution->allows('events'));
        $this->assertNull($resolution->limit('events_per_month'));
        $this->assertSame(90, $resolution->limit('retention_days'));
        $this->assertTrue(app(AnalyticsPlanAuthority::class)->canCollect(AnalyticsWorkspace::query()->findOrFail(41)));

        $secondRun = app(InitializeAnalyticsPlanSlotsInCore::class)->run(apply: true);

        $this->assertSame(1, $secondRun['plan_slots_already_initialized']);
        $this->assertDatabaseCount('product_subscriptions', 1, 'core');
        $this->assertDatabaseCount('current_product_subscriptions', 1, 'core');
    }

    public function test_unreconciled_workspaces_are_held_for_review_without_creating_a_plan(): void
    {
        $this->addAnalyticsWorkspace(52);

        $report = app(InitializeAnalyticsPlanSlotsInCore::class)->run(apply: true);
        $mapping = LegacyIdentityMap::query()
            ->where('source_product', 'analytics')
            ->where('source_entity', 'current_subscription')
            ->where('source_id', '52')
            ->firstOrFail();

        $this->assertSame(1, $report['workspaces_blocked']);
        $this->assertSame('needs_review', $mapping->status);
        $this->assertContains('analytics_workspace_not_reconciled', $mapping->metadata['reason_codes']);
        $this->assertDatabaseCount('product_subscriptions', 0, 'core');

        config(['analytics.plan_authority' => 'core']);
        $analyticsWorkspace = AnalyticsWorkspace::query()->findOrFail(52);
        $plans = app(AnalyticsPlanAuthority::class);
        $resolution = $plans->resolve($analyticsWorkspace);

        $this->assertFalse($resolution->available);
        $this->assertSame('workspace_mapping_missing', $resolution->unavailableReason);
        $this->assertFalse($plans->canCollect($analyticsWorkspace));
    }

    public function test_existing_analytics_slot_is_held_for_review_and_never_overwritten(): void
    {
        $workspaceId = $this->addReconciledAnalyticsWorkspace(63);
        $existing = ProductSubscription::query()->create([
            'workspace_id' => $workspaceId,
            'product' => 'analytics',
            'provider' => 'stripe',
            'provider_account_key' => 'analytics',
            'plan_key' => 'reviewed_plan',
            'status' => 'active',
            'quantity' => 1,
            'metadata' => ['plan_snapshot' => ['entitlements' => ['existing'], 'limits' => []]],
        ]);
        CurrentProductSubscription::query()->create([
            'workspace_id' => $workspaceId,
            'product' => 'analytics',
            'product_subscription_id' => $existing->getKey(),
        ]);

        $report = app(InitializeAnalyticsPlanSlotsInCore::class)->run(apply: true);
        $mapping = LegacyIdentityMap::query()
            ->where('source_product', 'analytics')
            ->where('source_entity', 'current_subscription')
            ->where('source_id', '63')
            ->firstOrFail();

        $this->assertSame(1, $report['workspaces_blocked']);
        $this->assertContains('core_analytics_subscription_slot_already_occupied', $mapping->metadata['reason_codes']);
        $this->assertDatabaseCount('product_subscriptions', 1, 'core');
        $this->assertSame('reviewed_plan', ProductSubscription::query()->firstOrFail()->plan_key);
    }

    private function addAnalyticsWorkspace(int $sourceWorkspaceId): void
    {
        $now = now();
        DB::connection('analytics')->table('workspaces')->insert([
            'id' => $sourceWorkspaceId,
            'name' => 'Analytics '.$sourceWorkspaceId,
            'slug' => 'analytics-'.$sourceWorkspaceId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function addReconciledAnalyticsWorkspace(int $sourceWorkspaceId): string
    {
        $this->addAnalyticsWorkspace($sourceWorkspaceId);
        $workspaceId = (string) Str::ulid();
        Workspace::query()->forceCreate([
            'id' => $workspaceId,
            'owner_user_id' => null,
            'name' => 'Canonical '.$sourceWorkspaceId,
            'slug' => 'canonical-'.$sourceWorkspaceId,
            'status' => 'active',
        ]);
        LegacyIdentityMap::query()->create([
            'source_product' => 'analytics',
            'source_entity' => 'workspace',
            'source_id' => (string) $sourceWorkspaceId,
            'canonical_entity' => 'workspace',
            'canonical_id' => $workspaceId,
            'status' => 'reconciled',
            'batch_key' => 'analytics-workspace-site-import-v1',
            'metadata' => [],
            'imported_at' => now(),
            'reconciled_at' => now(),
        ]);

        return $workspaceId;
    }

    private function createAnalyticsTables(): void
    {
        Schema::connection('analytics')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
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
    }
}
