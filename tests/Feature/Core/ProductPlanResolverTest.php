<?php

namespace Tests\Feature\Core;

use App\Core\Enums\ProductKey;
use App\Core\Services\Billing\ResolveProductPlan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductPlanResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        config(['platform.billing.entitled_statuses.deployer' => ['active', 'trialing']]);
    }

    protected function tearDown(): void
    {
        Schema::connection('core')->dropIfExists('current_product_subscriptions');
        Schema::connection('core')->dropIfExists('product_subscriptions');

        parent::tearDown();
    }

    public function test_it_resolves_only_the_immutable_snapshot_for_the_requested_workspace_and_product(): void
    {
        $workspaceId = (string) Str::ulid();
        $this->addPlan($workspaceId, 'deployer', 'pro', 'active', [
            'name' => 'Imported Pro',
            'entitlements' => ['deployments', 'monitoring'],
            'limits' => ['servers' => 8, 'websites' => null],
        ]);
        config(['billing.plans.pro.limits.servers' => 1]);

        $resolution = app(ResolveProductPlan::class)->resolve($workspaceId, ProductKey::Deployer);

        $this->assertTrue($resolution->available);
        $this->assertSame('pro', $resolution->planKey);
        $this->assertSame('Imported Pro', $resolution->planName);
        $this->assertTrue($resolution->allows('deployments'));
        $this->assertSame(8, $resolution->limit('servers'));
        $this->assertNull($resolution->limit('websites'));
        $this->assertFalse($resolution->allows('backups'));
    }

    public function test_it_rejects_a_subscription_status_outside_the_product_entitlement_policy(): void
    {
        $workspaceId = (string) Str::ulid();
        $this->addPlan($workspaceId, 'deployer', 'pro', 'past_due', [
            'entitlements' => ['*'],
            'limits' => ['servers' => null],
        ]);

        $resolution = app(ResolveProductPlan::class)->resolve($workspaceId, ProductKey::Deployer);

        $this->assertFalse($resolution->available);
        $this->assertSame('subscription_status_not_entitled', $resolution->unavailableReason);
        $this->assertFalse($resolution->allows('anything'));
    }

    public function test_it_rejects_expired_periods_and_malformed_plan_snapshots(): void
    {
        $expiredWorkspaceId = (string) Str::ulid();
        $this->addPlan($expiredWorkspaceId, 'deployer', 'pro', 'active', [
            'entitlements' => ['deployments'],
            'limits' => ['servers' => 2],
        ], now()->subMinute()->toDateTimeString());

        $expired = app(ResolveProductPlan::class)->resolve($expiredWorkspaceId, ProductKey::Deployer);
        $this->assertSame('subscription_period_expired', $expired->unavailableReason);

        $malformedWorkspaceId = (string) Str::ulid();
        $this->addPlan($malformedWorkspaceId, 'deployer', 'pro', 'active', [
            'entitlements' => ['deployments'],
            'limits' => ['servers' => 'unlimited'],
        ]);

        $malformed = app(ResolveProductPlan::class)->resolve($malformedWorkspaceId, ProductKey::Deployer);
        $this->assertSame('plan_snapshot_missing_or_invalid', $malformed->unavailableReason);
    }

    public function test_a_different_products_subscription_never_grants_deployer_entitlements(): void
    {
        $workspaceId = (string) Str::ulid();
        $this->addPlan($workspaceId, 'monitor', 'enterprise', 'active', [
            'entitlements' => ['*'],
            'limits' => ['servers' => null],
        ]);

        $resolution = app(ResolveProductPlan::class)->resolve($workspaceId, ProductKey::Deployer);

        $this->assertFalse($resolution->available);
        $this->assertSame('current_subscription_missing', $resolution->unavailableReason);
    }

    public function test_a_corrupt_cross_workspace_assignment_fails_closed(): void
    {
        $requestedWorkspaceId = (string) Str::ulid();
        $subscriptionId = (string) Str::ulid();
        $now = now();

        DB::connection('core')->table('product_subscriptions')->insert([
            'id' => $subscriptionId,
            'workspace_id' => (string) Str::ulid(),
            'product' => 'monitor',
            'plan_key' => 'enterprise',
            'status' => 'active',
            'metadata' => json_encode(['plan_snapshot' => [
                'entitlements' => ['*'],
                'limits' => ['servers' => null],
            ]], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('current_product_subscriptions')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $requestedWorkspaceId,
            'product' => 'deployer',
            'product_subscription_id' => $subscriptionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $resolution = app(ResolveProductPlan::class)->resolve($requestedWorkspaceId, ProductKey::Deployer);

        $this->assertFalse($resolution->available);
        $this->assertSame('current_subscription_mapping_invalid', $resolution->unavailableReason);
    }

    private function createTables(): void
    {
        Schema::connection('core')->create('product_subscriptions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('product', 24);
            $table->string('plan_key', 100)->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('current_period_ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('current_product_subscriptions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('product', 24);
            $table->char('product_subscription_id', 26);
            $table->timestamps();
        });
    }

    /** @param array<string, mixed> $snapshot */
    private function addPlan(
        string $workspaceId,
        string $product,
        string $planKey,
        string $status,
        array $snapshot,
        ?string $periodEnd = null,
    ): void {
        $subscriptionId = (string) Str::ulid();
        $now = now();

        DB::connection('core')->table('product_subscriptions')->insert([
            'id' => $subscriptionId,
            'workspace_id' => $workspaceId,
            'product' => $product,
            'plan_key' => $planKey,
            'status' => $status,
            'current_period_ends_at' => $periodEnd,
            'metadata' => json_encode(['plan_snapshot' => $snapshot], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('current_product_subscriptions')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspaceId,
            'product' => $product,
            'product_subscription_id' => $subscriptionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
