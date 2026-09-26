<?php

namespace Tests\Feature\Deployer;

use App\Core\Models\ProductSubscription;
use App\Modules\Deployer\Services\ReconcileDeployerCoreBillingEvents;
use App\Modules\Deployer\Services\SyncDeployerBillingEventIntoCore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SyncDeployerBillingEventIntoCoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.plans' => [
            'free' => ['name' => 'Free', 'entitlements' => ['deployments'], 'limits' => ['servers' => 1, 'api_requests_per_minute' => 60]],
            'pro' => [
                'name' => 'Pro',
                'monthly_price_id' => 'price_deployer_pro_monthly',
                'yearly_price_id' => 'price_deployer_pro_yearly',
                'yearly_seat_price_id' => 'price_deployer_pro_seat_yearly',
                'entitlements' => ['deployments', 'previews'],
                'limits' => ['servers' => 5, 'api_requests_per_minute' => 300],
            ],
        ]]);

        $this->dropTables();
        $this->createCoreTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_verified_deployer_events_update_only_the_deployer_slot_and_duplicates_are_idempotent(): void
    {
        $workspaceId = $this->addWorkspaceMapping(10);
        $freeSubscription = $this->addFreeSubscription($workspaceId);
        $this->assignCurrentSubscription($workspaceId, $freeSubscription->getKey());
        $monitorSubscription = $this->addProductSubscription($workspaceId, 'monitor', 'stripe', 'monitor', 'sub_monitor_kept', 'pro', 'active');
        $this->assignCurrentSubscription($workspaceId, $monitorSubscription->getKey(), 'monitor');
        $event = $this->event('evt_deployer_active', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(10));

        $this->assertTrue(app(SyncDeployerBillingEventIntoCore::class)->handle($event));

        $deployer = ProductSubscription::query()->where('product', 'deployer')->where('provider_subscription_id', 'sub_deployer_1')->firstOrFail();
        $deployerAssignment = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'deployer')->first();
        $billingEvent = DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_deployer_active')->first();

        $this->assertSame('stripe', $deployer->provider);
        $this->assertSame('deployer', $deployer->provider_account_key);
        $this->assertSame('pro', $deployer->plan_key);
        $this->assertSame('active', $deployer->status);
        $this->assertSame('yearly', $deployer->metadata['billing_interval']);
        $this->assertSame('previews', $deployer->metadata['plan_snapshot']['entitlements'][1]);
        $this->assertSame(300, $deployer->metadata['plan_snapshot']['limits']['api_requests_per_minute']);
        $this->assertSame($deployer->getKey(), $deployerAssignment->product_subscription_id);
        $this->assertSame($monitorSubscription->getKey(), DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'monitor')->value('product_subscription_id'));
        $this->assertSame('applied', $billingEvent->processing_status);
        $this->assertFalse(app(SyncDeployerBillingEventIntoCore::class)->handle($event));
        $this->assertSame(1, DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_deployer_active')->count());
    }

    public function test_subscription_events_project_only_recognized_deployer_seat_addons(): void
    {
        $workspaceId = $this->addWorkspaceMapping(15);
        $freeSubscription = $this->addFreeSubscription($workspaceId);
        $this->assignCurrentSubscription($workspaceId, $freeSubscription->getKey());
        $subscription = $this->subscriptionObject(15);
        $subscription['items']['data'][] = [
            'quantity' => 3,
            'price' => ['id' => 'price_deployer_pro_seat_yearly'],
        ];

        app(SyncDeployerBillingEventIntoCore::class)->handle(
            $this->event('evt_deployer_seat_items', 'customer.subscription.created', 1_800_000_100, $subscription),
        );

        $projected = ProductSubscription::query()->where('provider_subscription_id', 'sub_deployer_1')->firstOrFail();
        $this->assertSame([
            'additional_seats' => 3,
            'verified' => true,
            'source' => 'stripe_subscription_items',
        ], $projected->metadata['seat_billing']);

        $subscription['items']['data'][1]['price']['id'] = 'price_unrecognized_addon';
        app(SyncDeployerBillingEventIntoCore::class)->handle(
            $this->event('evt_deployer_unrecognized_seat_items', 'customer.subscription.updated', 1_800_000_200, $subscription),
        );

        $this->assertSame([
            'additional_seats' => null,
            'verified' => false,
            'source' => 'stripe_subscription_items',
        ], $projected->fresh()->metadata['seat_billing']);
    }

    public function test_cancellation_keeps_paid_history_and_restores_the_deployer_free_slot(): void
    {
        $workspaceId = $this->addWorkspaceMapping(20);
        $freeSubscription = $this->addFreeSubscription($workspaceId);
        $this->assignCurrentSubscription($workspaceId, $freeSubscription->getKey());
        $created = $this->subscriptionObject(20);
        app(SyncDeployerBillingEventIntoCore::class)->handle(
            $this->event('evt_deployer_created', 'customer.subscription.created', 1_800_000_000, $created),
        );

        $paid = ProductSubscription::query()->where('provider_subscription_id', 'sub_deployer_1')->firstOrFail();
        $deleted = $created;
        $deleted['status'] = 'canceled';
        $deleted['canceled_at'] = 1_800_000_100;
        app(SyncDeployerBillingEventIntoCore::class)->handle(
            $this->event('evt_deployer_deleted', 'customer.subscription.deleted', 1_800_000_100, $deleted),
        );

        $currentId = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'deployer')->value('product_subscription_id');
        $current = ProductSubscription::query()->findOrFail($currentId);

        $this->assertSame('canceled', $paid->fresh()->status);
        $this->assertSame('pro', $paid->fresh()->plan_key);
        $this->assertSame('deployer_legacy', $current->provider);
        $this->assertSame('free', $current->plan_key);
        $this->assertSame('active', $current->status);
    }

    public function test_new_paid_subscription_replaces_a_previously_ended_current_subscription(): void
    {
        $workspaceId = $this->addWorkspaceMapping(25);
        $ended = $this->addProductSubscription($workspaceId, 'deployer', 'stripe', 'deployer', 'sub_deployer_old', 'pro', 'canceled', [
            'plan_snapshot' => config('billing.plans.pro'),
        ]);
        $this->assignCurrentSubscription($workspaceId, $ended->getKey());

        app(SyncDeployerBillingEventIntoCore::class)->handle(
            $this->event('evt_deployer_renewed', 'customer.subscription.created', 1_800_000_500, $this->subscriptionObject(25)),
        );

        $currentId = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'deployer')->value('product_subscription_id');
        $current = ProductSubscription::query()->findOrFail($currentId);

        $this->assertSame('sub_deployer_old', $ended->fresh()->provider_subscription_id);
        $this->assertSame('canceled', $ended->fresh()->status);
        $this->assertSame('sub_deployer_1', $current->provider_subscription_id);
        $this->assertSame('active', $current->status);
    }

    public function test_conflicting_workspace_mapping_is_held_for_review_without_changing_entitlements(): void
    {
        $workspaceId = $this->addWorkspaceMapping(30);
        $otherWorkspaceId = $this->addWorkspace(31);
        $freeSubscription = $this->addFreeSubscription($workspaceId);
        $this->assignCurrentSubscription($workspaceId, $freeSubscription->getKey());
        $object = $this->subscriptionObject(30);
        $object['metadata']['core_workspace_id'] = $otherWorkspaceId;

        app(SyncDeployerBillingEventIntoCore::class)->handle(
            $this->event('evt_deployer_conflict', 'customer.subscription.created', 1_800_000_000, $object),
        );

        $event = DB::connection('core')->table('product_billing_events')->where('provider_event_id', 'evt_deployer_conflict')->first();
        $currentId = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'deployer')->value('product_subscription_id');

        $this->assertSame('pending_reconciliation', $event->processing_status);
        $this->assertSame('workspace_mapping_conflict', $event->ignored_reason);
        $this->assertSame($freeSubscription->getKey(), $currentId);
        $this->assertSame(0, DB::connection('core')->table('product_subscriptions')->where('provider_subscription_id', 'sub_deployer_1')->count());
    }

    public function test_pending_webhook_can_be_replayed_after_workspace_mapping_is_reconciled(): void
    {
        $workspaceId = $this->addWorkspace(40);
        $freeSubscription = $this->addFreeSubscription($workspaceId);
        $this->assignCurrentSubscription($workspaceId, $freeSubscription->getKey());
        $event = $this->event('evt_deployer_pending', 'customer.subscription.created', 1_800_000_000, $this->subscriptionObject(40, $workspaceId));
        app(SyncDeployerBillingEventIntoCore::class)->handle($event);
        $this->assertSame('pending_reconciliation', DB::connection('core')->table('product_billing_events')
            ->where('provider_event_id', 'evt_deployer_pending')->value('processing_status'));

        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'deployer',
            'source_entity' => 'organization',
            'source_id' => '40',
            'canonical_entity' => 'workspace',
            'canonical_id' => $workspaceId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config([
            'cashier.secret' => 'sk_test_deployer',
            'cashier.api_url' => 'https://api.stripe.com',
        ]);
        Http::fake(['https://api.stripe.com/v1/events/evt_deployer_pending' => Http::response($event, 200)]);

        $summary = app(ReconcileDeployerCoreBillingEvents::class)->handle(eventId: 'evt_deployer_pending');

        $this->assertSame(1, $summary['completed']);
        $this->assertSame('applied', DB::connection('core')->table('product_billing_events')
            ->where('provider_event_id', 'evt_deployer_pending')->value('processing_status'));
        $this->assertSame('pro', ProductSubscription::query()->where('provider_subscription_id', 'sub_deployer_1')->value('plan_key'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/events/evt_deployer_pending');
    }

    private function dropTables(): void
    {
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
            $table->string('slug')->unique();
            $table->string('status', 24)->default('active');
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

    private function addWorkspaceMapping(int $organizationId): string
    {
        $workspaceId = $this->addWorkspace($organizationId);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'deployer',
            'source_entity' => 'organization',
            'source_id' => (string) $organizationId,
            'canonical_entity' => 'workspace',
            'canonical_id' => $workspaceId,
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $workspaceId;
    }

    private function addWorkspace(int $suffix): string
    {
        $workspaceId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $workspaceId,
            'name' => 'Workspace '.$suffix,
            'slug' => 'workspace-'.$suffix,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $workspaceId;
    }

    private function addFreeSubscription(string $workspaceId): ProductSubscription
    {
        return $this->addProductSubscription($workspaceId, 'deployer', 'deployer_legacy', 'deployer', null, 'free', 'active', [
            'plan_snapshot' => config('billing.plans.free'),
            'entitlement_snapshot' => true,
        ]);
    }

    /** @param array<string,mixed> $metadata */
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
            'provider_price_id' => $product === 'deployer' && $plan !== 'free' ? 'price_deployer_pro_monthly' : null,
            'plan_key' => $plan,
            'status' => $status,
            'quantity' => 1,
            'metadata' => $metadata,
        ]);
    }

    private function assignCurrentSubscription(string $workspaceId, string $subscriptionId, string $product = 'deployer'): void
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

    /** @return array<string,mixed> */
    private function subscriptionObject(int $organizationId, ?string $coreWorkspaceId = null): array
    {
        $mapping = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')
            ->where('source_entity', 'organization')
            ->where('source_id', (string) $organizationId)
            ->first();

        return [
            'object' => 'subscription',
            'id' => 'sub_deployer_1',
            'customer' => 'cus_deployer_'.$organizationId,
            'status' => 'active',
            'metadata' => [
                'organization_id' => (string) $organizationId,
                'core_workspace_id' => $mapping?->canonical_id ?? $coreWorkspaceId,
                'plan' => 'pro',
                'interval' => 'yearly',
            ],
            'items' => ['data' => [[
                'quantity' => 1,
                'price' => ['id' => 'price_deployer_pro_yearly'],
            ]]],
            'current_period_start' => 1_800_000_000,
            'current_period_end' => 1_802_592_000,
        ];
    }

    /** @param array<string,mixed> $object
     * @return array<string,mixed>
     */
    private function event(string $id, string $type, int $created, array $object): array
    {
        return ['id' => $id, 'type' => $type, 'created' => $created, 'data' => ['object' => $object]];
    }
}
