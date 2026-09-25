<?php

namespace Tests\Feature\Core;

use App\Modules\Deployer\Services\Migration\ImportSubscriptionsIntoCore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeployerSubscriptionImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.plans' => [
            'free' => ['price_id' => null, 'monthly_price_id' => null, 'yearly_price_id' => null],
            'pro' => [
                'price_id' => 'price_pro',
                'monthly_price_id' => 'price_pro_monthly',
                'yearly_price_id' => 'price_pro_yearly',
                'monthly_seat_price_id' => 'price_pro_seat_monthly',
                'yearly_seat_price_id' => 'price_pro_seat_yearly',
            ],
            'team' => [
                'price_id' => null,
                'monthly_price_id' => 'price_team_monthly',
                'yearly_price_id' => 'price_team_yearly',
                'monthly_seat_price_id' => 'price_team_seat_monthly',
                'yearly_seat_price_id' => 'price_team_seat_yearly',
            ],
        ]]);

        $this->createCoreTables();
        $this->createDeployerTables();
    }

    protected function tearDown(): void
    {
        foreach (['subscription_items', 'subscriptions', 'organizations', 'users'] as $table) {
            Schema::connection('deployer')->dropIfExists($table);
        }

        foreach ([
            'current_product_subscriptions', 'product_subscriptions', 'billing_customers',
            'legacy_identity_maps', 'workspaces',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_preview_and_apply_preserve_cashier_customer_prices_and_subscription_items(): void
    {
        $workspaceId = $this->addMappedOwnerAndOrganization(10, 1);
        $this->addUser(1, 'cus_deployer', 'card', '4242');
        $this->addSubscription(100, 1, 'sub_deployer', 'active', null, null, null);
        $this->addSubscriptionItem(200, 100, 'si_base', 'prod_team', 'price_team_yearly', 1);
        $this->addSubscriptionItem(201, 100, 'si_seats', 'prod_seat', 'price_team_seat_yearly', 4);

        $importer = app(ImportSubscriptionsIntoCore::class);
        $preview = $importer->run();

        $this->assertSame(1, $preview['subscriptions_ready']);
        $this->assertSame(1, $preview['subscriptions_seen']);
        $this->assertSame(0, $preview['subscriptions_imported']);
        $this->assertSame(0, DB::connection('core')->table('product_subscriptions')->count());
        $this->assertSame(0, DB::connection('core')->table('billing_customers')->count());

        $applied = $importer->run(apply: true);
        $subscription = DB::connection('core')->table('product_subscriptions')
            ->where('provider_subscription_id', 'sub_deployer')->first();
        $metadata = json_decode($subscription->metadata, true, 512, JSON_THROW_ON_ERROR);
        $assignment = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'deployer')->first();
        $currentMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')->where('source_entity', 'current_subscription')->where('source_id', '10')->first();
        $customerMetadata = json_decode(DB::connection('core')->table('billing_customers')
            ->where('provider_customer_id', 'cus_deployer')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $applied['subscriptions_imported']);
        $this->assertSame(1, $applied['stripe_subscriptions_imported']);
        $this->assertSame(1, $applied['billing_customers_imported']);
        $this->assertSame('deployer', $subscription->product);
        $this->assertSame('deployer', $subscription->provider_account_key);
        $this->assertSame('team', $subscription->plan_key);
        $this->assertSame('price_team_yearly', $subscription->provider_price_id);
        $this->assertSame('yearly', $metadata['billing_interval']);
        $this->assertSame('si_base', $metadata['items'][0]['stripe_item_id']);
        $this->assertSame('price_team_seat_yearly', $metadata['items'][1]['stripe_price_id']);
        $this->assertSame(4, $metadata['items'][1]['quantity']);
        $this->assertSame('4242', $customerMetadata['payment_method_last_four']);
        $this->assertSame($subscription->id, $assignment->product_subscription_id);
        $this->assertSame('current_product_subscription', $currentMap->canonical_entity);

        $secondRun = $importer->run(apply: true);
        $this->assertSame(1, $secondRun['subscriptions_already_mapped']);
        $this->assertSame(1, DB::connection('core')->table('product_subscriptions')->count());
        $this->assertSame(1, DB::connection('core')->table('billing_customers')->count());
    }

    public function test_cashier_period_end_cancellation_remains_entitled_until_its_grace_period_ends(): void
    {
        $workspaceId = $this->addMappedOwnerAndOrganization(20, 2);
        $this->addUser(2, 'cus_grace');
        $this->addSubscription(120, 2, 'sub_grace', 'canceled', 'price_pro', now()->addDays(20)->toDateTimeString(), null);

        app(ImportSubscriptionsIntoCore::class)->run(apply: true);

        $assignment = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'deployer')->first();
        $subscription = DB::connection('core')->table('product_subscriptions')->find($assignment->product_subscription_id);

        $this->assertSame('active', $subscription->status);
        $this->assertSame('pro', $subscription->plan_key);
        $this->assertNotNull($subscription->cancel_at);
        $this->assertNotNull($subscription->current_period_ends_at);
        $this->assertNull($subscription->canceled_at);
    }

    public function test_shared_owner_subscription_is_held_for_review_instead_of_duplicated(): void
    {
        $this->addUser(30, 'cus_shared');
        $firstWorkspaceId = $this->addMappedOwnerAndOrganization(30, 30, 'Workspace one');
        $secondWorkspaceId = $this->addMappedOwnerAndOrganization(31, 30, 'Workspace two');
        $this->addSubscription(130, 30, 'sub_shared', 'active', 'price_pro', null, null);

        $report = app(ImportSubscriptionsIntoCore::class)->run(apply: true);
        $reviewMaps = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')->where('source_entity', 'current_subscription')
            ->whereIn('source_id', ['30', '31'])->get();

        $this->assertSame(2, $report['subscriptions_blocked']);
        $this->assertSame(2, $report['review_records_created']);
        $this->assertCount(2, $reviewMaps);
        $this->assertSame(0, DB::connection('core')->table('product_subscriptions')->count());
        $this->assertSame(0, DB::connection('core')->table('billing_customers')->count());
        $this->assertSame(0, DB::connection('core')->table('current_product_subscriptions')
            ->whereIn('workspace_id', [$firstWorkspaceId, $secondWorkspaceId])->count());
        $firstMetadata = json_decode($reviewMaps->firstWhere('source_id', '30')->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('deployer_owner_billing_shared_across_workspaces', $firstMetadata['reason_codes']);
    }

    public function test_unknown_active_price_is_reviewed_and_can_be_retried_after_catalog_correction(): void
    {
        $workspaceId = $this->addMappedOwnerAndOrganization(40, 40);
        $this->addUser(40, 'cus_unknown');
        $this->addSubscription(140, 40, 'sub_unknown', 'active', 'price_enterprise', null, null);

        $importer = app(ImportSubscriptionsIntoCore::class);
        $firstRun = $importer->run(apply: true);
        $reviewMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')->where('source_entity', 'current_subscription')->where('source_id', '40')->first();
        $reviewMetadata = json_decode($reviewMap->metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $firstRun['subscriptions_blocked']);
        $this->assertContains('deployer_active_subscription_plan_price_unrecognized', $reviewMetadata['reason_codes']);
        $this->assertSame(0, DB::connection('core')->table('product_subscriptions')->count());

        config(['billing.plans.enterprise' => [
            'price_id' => 'price_enterprise',
            'monthly_price_id' => 'price_enterprise',
            'yearly_price_id' => null,
        ]]);
        $retry = $importer->run(apply: true);
        $resolvedMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')->where('source_entity', 'current_subscription')->where('source_id', '40')->first();
        $resolvedMetadata = json_decode($resolvedMap->metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $retry['subscriptions_imported']);
        $this->assertSame('reconciled', $resolvedMap->status);
        $this->assertSame('enterprise', DB::connection('core')->table('product_subscriptions')
            ->where('workspace_id', $workspaceId)->value('plan_key'));
        $this->assertContains('deployer_active_subscription_plan_price_unrecognized', $resolvedMetadata['review_history'][0]['reason_codes']);
    }

    public function test_owner_without_a_cashier_subscription_gets_a_free_current_plan_snapshot(): void
    {
        $workspaceId = $this->addMappedOwnerAndOrganization(50, 50);
        $this->addUser(50);

        app(ImportSubscriptionsIntoCore::class)->run(apply: true);

        $assignment = DB::connection('core')->table('current_product_subscriptions')
            ->where('workspace_id', $workspaceId)->where('product', 'deployer')->first();
        $subscription = DB::connection('core')->table('product_subscriptions')->find($assignment->product_subscription_id);

        $this->assertSame('deployer_legacy', $subscription->provider);
        $this->assertSame('free', $subscription->plan_key);
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->provider_subscription_id);
    }

    public function test_subscription_without_an_owner_workspace_is_reviewed_and_retried_after_workspace_mapping(): void
    {
        $this->addUser(60);
        $this->addSubscription(160, 60, 'sub_orphan', 'active', 'price_pro', null, null);
        $importer = app(ImportSubscriptionsIntoCore::class);

        $firstRun = $importer->run(apply: true);
        $reviewMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')->where('source_entity', 'subscription')->where('source_id', '160')->first();
        $reviewMetadata = json_decode($reviewMap->metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $firstRun['subscriptions_without_workspace_owner']);
        $this->assertSame('needs_review', $reviewMap->status);
        $this->assertContains('deployer_subscription_owner_has_no_organization', $reviewMetadata['reason_codes']);
        $this->assertSame(0, DB::connection('core')->table('product_subscriptions')->count());

        $this->addMappedOwnerAndOrganization(60, 60);
        $retry = $importer->run(apply: true);
        $resolvedMap = DB::connection('core')->table('legacy_identity_maps')
            ->where('source_product', 'deployer')->where('source_entity', 'subscription')->where('source_id', '160')->first();
        $resolvedMetadata = json_decode($resolvedMap->metadata, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $retry['subscriptions_imported']);
        $this->assertSame('reconciled', $resolvedMap->status);
        $this->assertContains('deployer_subscription_owner_has_no_organization', $resolvedMetadata['review_history'][0]['reason_codes']);
        $this->assertSame('sub_orphan', DB::connection('core')->table('product_subscriptions')->value('provider_subscription_id'));
    }

    private function createCoreTables(): void
    {
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26);
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
    }

    private function createDeployerTables(): void
    {
        Schema::connection('deployer')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('stripe_id')->nullable();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        Schema::connection('deployer')->create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('deployer')->create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->timestamps();
        });
    }

    private function addMappedOwnerAndOrganization(
        int $organizationId,
        int $ownerId,
        string $name = 'Deployer workspace',
    ): string {
        $workspaceId = (string) Str::ulid();
        DB::connection('core')->table('workspaces')->insert([
            'id' => $workspaceId,
            'owner_user_id' => '00000000000000000000000001',
            'name' => $name,
            'slug' => 'deployer-'.$organizationId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
        DB::connection('core')->table('legacy_identity_maps')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'source_product' => 'deployer',
            'source_entity' => 'user',
            'source_id' => (string) $ownerId,
            'canonical_entity' => 'user',
            'canonical_id' => '00000000000000000000000001',
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('deployer')->table('organizations')->insert([
            'id' => $organizationId,
            'owner_id' => $ownerId,
            'name' => $name,
            'slug' => 'deployer-'.$organizationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $workspaceId;
    }

    private function addUser(int $id, ?string $stripeId = null, ?string $paymentType = null, ?string $lastFour = null): void
    {
        DB::connection('deployer')->table('users')->insert([
            'id' => $id,
            'name' => 'Owner '.$id,
            'email' => 'owner'.$id.'@example.test',
            'stripe_id' => $stripeId,
            'pm_type' => $paymentType,
            'pm_last_four' => $lastFour,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addSubscription(
        int $id,
        int $userId,
        string $stripeId,
        string $status,
        ?string $price,
        ?string $endsAt,
        ?string $trialEndsAt,
    ): void {
        DB::connection('deployer')->table('subscriptions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $status,
            'stripe_price' => $price,
            'quantity' => 1,
            'ends_at' => $endsAt,
            'trial_ends_at' => $trialEndsAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addSubscriptionItem(
        int $id,
        int $subscriptionId,
        string $stripeId,
        string $productId,
        string $priceId,
        int $quantity,
    ): void {
        DB::connection('deployer')->table('subscription_items')->insert([
            'id' => $id,
            'subscription_id' => $subscriptionId,
            'stripe_id' => $stripeId,
            'stripe_product' => $productId,
            'stripe_price' => $priceId,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
