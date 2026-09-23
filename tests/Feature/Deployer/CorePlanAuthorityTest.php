<?php

namespace Tests\Feature\Deployer;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Entitlements;
use App\Modules\Deployer\Services\PlanLimits;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CorePlanAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCoreTables();
        config([
            'billing.plan_authority' => 'core',
            'billing.enforce_limits' => true,
            'billing.enforce_entitlements' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::connection('core')->dropIfExists('current_product_subscriptions');
        Schema::connection('core')->dropIfExists('product_subscriptions');
        Schema::connection('core')->dropIfExists('legacy_identity_maps');

        parent::tearDown();
    }

    public function test_deployer_features_and_limits_use_the_same_reconciled_workspace_snapshot(): void
    {
        $user = User::factory()->create();
        $organization = $user->currentOrganization;
        $this->addWorkspacePlan($organization, [
            'name' => 'Imported Pro',
            'entitlements' => ['deployments', 'monitoring'],
            'limits' => ['servers' => 1, 'websites' => 4, 'members' => 3, 'preview_deployments' => 2],
        ]);
        $user->servers()->create(['name' => 'Production']);

        $entitlements = app(Entitlements::class);
        $usage = app(PlanLimits::class)->usageForOrganization($organization, 'servers');

        $this->assertTrue($entitlements->allows($organization, 'deployments'));
        $this->assertFalse($entitlements->allows($organization, 'backups'));
        $this->assertSame('pro', $usage['plan']);
        $this->assertSame(1, $usage['limit']);
        $this->assertSame(1, $usage['used']);
        $this->assertFalse($usage['allowed']);

        try {
            app(PlanLimits::class)->enforceForOrganization($organization, 'servers');
            $this->fail('The Core workspace quota should reject another server.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Imported Pro plan allows 1 server', $exception->errors()['plan'][0]);
        }
    }

    public function test_missing_reconciliation_fails_closed_without_using_the_legacy_owner_plan(): void
    {
        config(['billing.plans.free.entitlements' => ['*']]);
        $user = User::factory()->create();
        $organization = $user->currentOrganization;

        $this->assertFalse(app(Entitlements::class)->allows($organization, 'deployments'));

        $usage = app(PlanLimits::class)->usageForOrganization($organization, 'servers');
        $this->assertFalse($usage['plan_available']);
        $this->assertFalse($usage['allowed']);

        try {
            app(PlanLimits::class)->enforceForOrganization($organization, 'servers');
            $this->fail('A missing Core mapping must stop resource creation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('could not confirm this workspace', strtolower($exception->errors()['plan'][0]));
        }
    }

    private function createCoreTables(): void
    {
        Schema::connection('core')->create('legacy_identity_maps', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('source_product', 24);
            $table->string('source_entity', 100);
            $table->string('source_id', 191);
            $table->string('canonical_entity', 100)->nullable();
            $table->char('canonical_id', 26)->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamps();
        });
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
    private function addWorkspacePlan(Organization $organization, array $snapshot): void
    {
        $workspaceId = (string) Str::ulid();
        $subscriptionId = (string) Str::ulid();
        $now = now();

        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'deployer',
            'source_entity' => 'organization',
            'source_id' => (string) $organization->getKey(),
            'canonical_entity' => 'workspace',
            'canonical_id' => $workspaceId,
            'status' => 'reconciled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('product_subscriptions')->insert([
            'id' => $subscriptionId,
            'workspace_id' => $workspaceId,
            'product' => 'deployer',
            'plan_key' => 'pro',
            'status' => 'active',
            'current_period_ends_at' => now()->addMonth(),
            'metadata' => json_encode(['plan_snapshot' => $snapshot], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('current_product_subscriptions')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspaceId,
            'product' => 'deployer',
            'product_subscription_id' => $subscriptionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
