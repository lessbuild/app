<?php

namespace Tests\Feature\Monitor;

use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\PruneTelemetryData;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use App\Modules\Monitor\Services\WorkspaceUsage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CorePlanAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.beacon.plan_authority' => 'core']);
        $this->createCoreTables();
        $this->createMonitorTables();
    }

    protected function tearDown(): void
    {
        Schema::connection('monitor')->dropIfExists('applications');
        Schema::connection('monitor')->dropIfExists('telemetry_usage_entries');
        Schema::connection('monitor')->dropIfExists('workspaces');
        Schema::connection('core')->dropIfExists('current_product_subscriptions');
        Schema::connection('core')->dropIfExists('product_subscriptions');
        Schema::connection('core')->dropIfExists('legacy_identity_maps');

        parent::tearDown();
    }

    public function test_unmapped_workspace_fails_closed_for_creation_and_skips_retention_cleanup(): void
    {
        $workspaceId = $this->addMonitorWorkspace();
        $workspace = Workspace::query()->findOrFail($workspaceId);

        $capacity = app(WorkspacePlanLimits::class)->applicationCapacity($workspace);

        $this->assertFalse($capacity['plan_available']);
        $this->assertFalse($capacity['limit_configured']);
        $this->assertTrue($capacity['at_limit']);
        $this->assertFalse(app(WorkspacePlanLimits::class)->issueDigestEnabled($workspace));
        $this->assertSame(0, app(WorkspaceUsage::class)->eventLimit($workspace));
        $this->assertFalse(app(WorkspaceUsage::class)->summary($workspace)['event_limit_is_finite']);

        try {
            app(WorkspacePlanLimits::class)->assertApplicationCapacity($workspace);
            $this->fail('Monitor must stop resource creation without a reconciled Core subscription.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('could not confirm', strtolower($exception->errors()['plan'][0]));
        }

        $summary = app(PruneTelemetryData::class)->prune(dryRun: true);
        $this->assertSame(0, $summary['workspaces']);
        $this->assertSame(1, $summary['workspaces_skipped_without_retention_window']);
    }

    public function test_reconciled_monitor_snapshot_controls_quotas_features_and_usage(): void
    {
        $workspaceId = $this->addMonitorWorkspace();
        $canonicalWorkspaceId = (string) Str::ulid();
        $this->addCurrentMonitorPlan($workspaceId, $canonicalWorkspaceId, [
            'name' => 'Imported Pro',
            'entitlements' => ['issue_digest', 'telemetry_guardrails'],
            'limits' => [
                'applications' => 1,
                'seats' => 5,
                'dashboards' => 4,
                'escalation_steps' => 3,
                'deployment_context_minutes' => 60,
                'events_per_month' => 10_000_000,
                'retention_days' => 30,
            ],
        ]);
        DB::connection('monitor')->table('applications')->insert([
            'workspace_id' => $workspaceId,
            'name' => 'Production',
        ]);
        $workspace = Workspace::query()->findOrFail($workspaceId);

        $limits = app(WorkspacePlanLimits::class);
        $capacity = $limits->applicationCapacity($workspace);

        $this->assertTrue($capacity['plan_available']);
        $this->assertTrue($capacity['limit_configured']);
        $this->assertSame(1, $capacity['limit']);
        $this->assertTrue($capacity['at_limit']);
        $this->assertTrue($limits->telemetryGuardrailsEnabled($workspace));
        $this->assertFalse($limits->sloReportsEnabled($workspace));
        $this->assertSame(60, $limits->deploymentContextMinutes($workspace));
        $this->assertSame(30, $limits->retentionDays($workspace));
        $this->assertSame(10_000_000, app(WorkspaceUsage::class)->eventLimit($workspace));
        $this->assertTrue(app(WorkspaceUsage::class)->summary($workspace)['event_limit_is_finite']);

        try {
            $limits->assertApplicationCapacity($workspace);
            $this->fail('The reconciled Core application quota should stop another application.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Imported Pro plan allows 1 application', $exception->errors()['plan'][0]);
        }
    }

    public function test_explicitly_unlimited_monitor_event_allowance_is_not_finite(): void
    {
        $workspaceId = $this->addMonitorWorkspace();
        $workspace = Workspace::query()->findOrFail($workspaceId);
        $this->addCurrentMonitorPlan($workspaceId, (string) Str::ulid(), [
            'name' => 'Unlimited Monitor',
            'entitlements' => ['*'],
            'limits' => ['events_per_month' => null],
        ]);

        $summary = app(WorkspaceUsage::class)->summary($workspace);

        $this->assertTrue($summary['plan_available']);
        $this->assertFalse($summary['event_limit_is_finite']);
        $this->assertSame(PHP_INT_MAX, $summary['event_limit']);
        $this->assertSame('healthy', $summary['state']);
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

    private function createMonitorTables(): void
    {
        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('plan')->default('free');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::connection('monitor')->create('telemetry_usage_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('environment_id')->nullable();
            $table->char('ingest_receipt_id', 26)->nullable();
            $table->string('source', 32);
            $table->unsignedInteger('event_count');
            $table->timestamp('received_at', 6);
            $table->timestamps(6);
        });
    }

    private function addMonitorWorkspace(): int
    {
        return (int) DB::connection('monitor')->table('workspaces')->insertGetId([
            'plan' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    private function addCurrentMonitorPlan(int $sourceWorkspaceId, string $canonicalWorkspaceId, array $snapshot): void
    {
        $subscriptionId = (string) Str::ulid();
        $now = now();
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'monitor',
            'source_entity' => 'workspace',
            'source_id' => (string) $sourceWorkspaceId,
            'canonical_entity' => 'workspace',
            'canonical_id' => $canonicalWorkspaceId,
            'status' => 'reconciled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('product_subscriptions')->insert([
            'id' => $subscriptionId,
            'workspace_id' => $canonicalWorkspaceId,
            'product' => 'monitor',
            'plan_key' => 'pro',
            'status' => 'active',
            'current_period_ends_at' => now()->addMonth(),
            'metadata' => json_encode(['plan_snapshot' => $snapshot], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('current_product_subscriptions')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $canonicalWorkspaceId,
            'product' => 'monitor',
            'product_subscription_id' => $subscriptionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
