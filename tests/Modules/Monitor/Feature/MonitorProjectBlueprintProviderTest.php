<?php

namespace Tests\Modules\Monitor\Feature;

use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectBlueprint;
use App\Core\Models\ProjectBlueprintRun;
use App\Core\Models\ProjectBlueprintStep;
use App\Core\Models\ProjectBlueprintVersion;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Blueprints\BlueprintAuthority;
use App\Core\Services\Blueprints\BlueprintFingerprint;
use App\Modules\Monitor\Models\BlueprintApplicationReceipt;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\User as MonitorUser;
use App\Modules\Monitor\Models\Workspace as MonitorWorkspace;
use App\Modules\Monitor\Services\Core\MonitorProjectBlueprintProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Regression cases for Monitor blueprint provisioning; intentionally not executed here. */
final class MonitorProjectBlueprintProviderTest extends TestCase
{
    private PlatformUser $actor;

    private CoreWorkspace $coreWorkspace;

    private CoreProject $project;

    private ProjectEnvironment $environment;

    private MonitorUser $nativeActor;

    private MonitorWorkspace $nativeWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['core', 'monitor'] as $connection) {
            config(["database.connections.{$connection}.database" => ':memory:']);
            DB::purge($connection);
            $this->assertSame(0, Artisan::call('platform:migrate', ['module' => $connection]));
        }
        config([
            'platform.products.monitor.enabled' => true,
            'platform.products.monitor.auth_authority' => 'core',
            'monitor.beacon.plan_authority' => 'legacy',
            'monitor.beacon.plans.free.apps' => 10,
        ]);

        $this->actor = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(), 'name' => 'Monitor Blueprint Owner', 'email' => 'monitor-blueprint@example.test',
            'email_normalized' => 'monitor-blueprint@example.test', 'password' => 'hashed', 'status' => 'active',
        ]);
        $this->coreWorkspace = CoreWorkspace::query()->create([
            'owner_user_id' => $this->actor->getKey(), 'name' => 'Core workspace', 'slug' => 'monitor-blueprint-'.Str::lower(Str::random(8)), 'status' => 'active',
        ]);
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->coreWorkspace->getKey(), 'user_id' => $this->actor->getKey(),
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        WorkspaceProductAccess::query()->create([
            'membership_id' => $membership->getKey(), 'product' => 'monitor', 'role' => 'owner', 'status' => 'active',
        ]);
        $this->project = CoreProject::query()->create([
            'workspace_id' => $this->coreWorkspace->getKey(), 'created_by_user_id' => $this->actor->getKey(),
            'name' => 'Checkout', 'slug' => 'checkout-'.Str::lower(Str::random(8)), 'status' => 'active',
        ]);
        ProjectMembership::query()->create([
            'project_id' => $this->project->getKey(), 'user_id' => $this->actor->getKey(), 'role' => 'owner', 'status' => 'active',
        ]);
        ProjectProduct::query()->create(['project_id' => $this->project->getKey(), 'product' => 'monitor', 'status' => 'active']);
        $this->environment = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'created_by_user_id' => $this->actor->getKey(), 'name' => 'Production',
            'slug' => 'production', 'environment_type' => 'production', 'status' => 'active',
        ]);
        $this->nativeActor = MonitorUser::query()->forceCreate([
            'name' => $this->actor->name, 'email' => 'monitor-native@example.test', 'password' => 'hashed', 'email_verified_at' => now(),
        ]);
        $this->nativeWorkspace = MonitorWorkspace::query()->create([
            'owner_id' => $this->nativeActor->getKey(), 'name' => 'Native Monitor workspace',
            'slug' => 'monitor-native-'.Str::lower(Str::random(8)), 'plan' => 'free',
        ]);
        $this->nativeWorkspace->members()->attach($this->nativeActor, ['role' => 'owner']);
        foreach ([
            ['user', (string) $this->nativeActor->getKey(), 'user', (string) $this->actor->getKey()],
            ['workspace', (string) $this->nativeWorkspace->getKey(), 'workspace', (string) $this->coreWorkspace->getKey()],
        ] as [$sourceEntity, $sourceId, $canonicalEntity, $canonicalId]) {
            DB::connection('core')->table('legacy_identity_maps')->insert([
                'id' => (string) Str::ulid(), 'source_product' => 'monitor', 'source_entity' => $sourceEntity,
                'source_id' => $sourceId, 'canonical_entity' => $canonicalEntity, 'canonical_id' => $canonicalId,
                'status' => 'reconciled', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_preview_is_read_only_and_reports_application_environment_token_and_check_work(): void
    {
        [$target, $configuration] = $this->definition();
        $preview = app(MonitorProjectBlueprintProvider::class)->preview($target, $configuration);

        $this->assertTrue($preview->ready());
        $this->assertContains('Create one Monitor application.', $preview->changes);
        $this->assertNotEmpty($preview->requirements);
        $this->assertSame(1, $preview->planImpact['applications_after']);
        $this->assertDatabaseCount('applications', 0, 'monitor');
        $this->assertDatabaseCount('environments', 0, 'monitor');
        $this->assertDatabaseCount('monitors', 0, 'monitor');
        $this->assertDatabaseCount('blueprint_application_receipts', 0, 'monitor');
    }

    public function test_native_apply_receipt_replays_without_duplicate_apps_environments_or_checks(): void
    {
        [$target, $configuration] = $this->definition();
        $provider = app(MonitorProjectBlueprintProvider::class);
        $preview = $provider->preview($target, $configuration);
        $attempt = $this->acceptedAttempt($target, $configuration, $preview->authority);

        $first = $provider->apply($attempt);
        $replay = $provider->apply($attempt);

        $this->assertSame($first->toArray(), $replay->toArray());
        $this->assertCount(2, $first->resources);
        $this->assertDatabaseCount('applications', 1, 'monitor');
        $this->assertDatabaseCount('environments', 1, 'monitor');
        $this->assertDatabaseCount('monitors', 1, 'monitor');
        $this->assertDatabaseCount('blueprint_application_receipts', 1, 'monitor');
        $this->assertSame('unknown', Monitor::query()->sole()->health);
        $this->assertNotNull(Monitor::query()->sole()->next_check_at);
        $this->assertDatabaseMissing('project_resources', ['product' => 'monitor'], 'core');
        $this->assertCount(1, BlueprintApplicationReceipt::query()->sole()->result['check_ids']);
    }

    public function test_preview_creation_target_accepts_core_assigned_environment_id_before_native_apply(): void
    {
        $targetWithoutId = new BlueprintTarget((string) $this->actor->getKey(), (string) $this->coreWorkspace->getKey(), (string) $this->project->getKey(), [
            'staging' => ['id' => null, 'name' => 'Staging', 'type' => 'staging'],
        ]);
        $configuration = [
            'application' => ['name' => 'Checkout API', 'framework' => 'Laravel', 'framework_version' => null, 'accent' => 'violet'],
            'environments' => [['environment' => 'staging']], 'checks' => [],
        ];
        $provider = app(MonitorProjectBlueprintProvider::class);
        $preview = $provider->preview($targetWithoutId, $configuration);
        $coreEnvironment = ProjectEnvironment::query()->create([
            'project_id' => $this->project->getKey(), 'created_by_user_id' => $this->actor->getKey(), 'name' => 'Staging',
            'slug' => 'staging', 'environment_type' => 'staging', 'status' => 'active',
        ]);
        $applyTarget = new BlueprintTarget((string) $this->actor->getKey(), (string) $this->coreWorkspace->getKey(), (string) $this->project->getKey(), [
            'staging' => ['id' => (string) $coreEnvironment->getKey(), 'name' => 'Staging', 'type' => 'staging'],
        ]);
        $attempt = $this->acceptedAttempt($applyTarget, $configuration, $preview->authority);

        $result = $provider->apply($attempt);

        $this->assertSame('staging', $result->resources[1]->environmentKey);
        $this->assertDatabaseCount('applications', 1, 'monitor');
        $this->assertDatabaseCount('environments', 1, 'monitor');
    }

    public function test_apply_blocks_if_core_product_authority_is_revoked_after_preview(): void
    {
        [$target, $configuration] = $this->definition();
        $provider = app(MonitorProjectBlueprintProvider::class);
        $preview = $provider->preview($target, $configuration);
        $attempt = $this->acceptedAttempt($target, $configuration, $preview->authority);
        DB::connection('core')->table('workspace_product_access')->where('product', 'monitor')->update(['status' => 'revoked']);

        try {
            $provider->apply($attempt);
            $this->fail('A revoked Core product grant must prevent local provisioning.');
        } catch (BlueprintBlocked) {
            $this->assertDatabaseCount('applications', 0, 'monitor');
            $this->assertDatabaseCount('blueprint_application_receipts', 0, 'monitor');
        }
    }

    public function test_preview_blocks_when_current_monitor_application_allowance_is_exhausted(): void
    {
        config(['monitor.beacon.plans.free.apps' => 0]);
        [$target, $configuration] = $this->definition();

        $preview = app(MonitorProjectBlueprintProvider::class)->preview($target, $configuration);

        $this->assertFalse($preview->ready());
        $this->assertDatabaseCount('applications', 0, 'monitor');
    }

    public function test_apply_blocks_when_core_resource_scope_changes_after_preview(): void
    {
        [$target, $configuration] = $this->definition();
        $provider = app(MonitorProjectBlueprintProvider::class);
        $preview = $provider->preview($target, $configuration);
        $attempt = $this->acceptedAttempt($target, $configuration, $preview->authority);
        DB::connection('core')->table('project_resources')->insert([
            'id' => (string) Str::ulid(), 'project_id' => $this->project->getKey(), 'environment_id' => null,
            'product' => 'monitor', 'resource_type' => 'application', 'resource_id' => 'different-app',
            'name' => 'Concurrent map', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $provider->apply($attempt);
            $this->fail('A changed Core resource scope must block source provisioning.');
        } catch (BlueprintBlocked) {
            $this->assertDatabaseCount('applications', 0, 'monitor');
            $this->assertDatabaseCount('blueprint_application_receipts', 0, 'monitor');
        }
    }

    public function test_normalizer_rejects_secret_fields_unsupported_check_types_and_unselected_environment_keys(): void
    {
        $provider = app(MonitorProjectBlueprintProvider::class);
        $this->expectException(ValidationException::class);
        $provider->normalize([
            'application' => ['name' => 'Checkout', 'framework' => 'Laravel', 'accent' => 'violet'],
            'environments' => [['environment' => 'production']],
            'checks' => [['environment' => 'production', 'name' => 'Private check', 'request_url' => 'https://example.test',
                'interval_minutes' => 5, 'timeout_seconds' => 5, 'bearer_token' => 'never accepted']],
        ]);
    }

    public function test_normalizer_accepts_custom_public_health_paths_and_preview_handles_unknown_environment_keys(): void
    {
        $provider = app(MonitorProjectBlueprintProvider::class);
        $configuration = [
            'application' => ['name' => 'Custom health app', 'framework' => 'Laravel', 'framework_version' => null, 'accent' => 'violet'],
            'environments' => [['environment' => 'unknown']],
            'checks' => [['environment' => 'unknown', 'name' => 'Readiness', 'request_url' => 'https://example.test/ready',
                'interval_minutes' => 5, 'timeout_seconds' => 5]],
        ];

        $normalized = $provider->normalize($configuration);
        $this->assertSame('https://example.test/ready', $normalized['checks'][0]['request_url']);
        [$target] = $this->definition();
        $preview = $provider->preview($target, $configuration);
        $this->assertFalse($preview->ready());
        $this->assertNotEmpty($preview->blockers);
    }

    /** @return array{BlueprintTarget, array<string, mixed>} */
    private function definition(): array
    {
        return [new BlueprintTarget((string) $this->actor->getKey(), (string) $this->coreWorkspace->getKey(), (string) $this->project->getKey(), [
            'production' => ['id' => (string) $this->environment->getKey(), 'name' => 'Production', 'type' => 'production'],
        ]), [
            'application' => ['name' => 'Checkout API', 'framework' => 'Laravel', 'framework_version' => '12', 'accent' => 'sky'],
            'environments' => [['environment' => 'production']],
            'checks' => [['environment' => 'production', 'name' => 'Checkout health', 'request_url' => 'https://example.test/health',
                'interval_minutes' => 5, 'timeout_seconds' => 5]],
        ]];
    }

    private function acceptedAttempt(BlueprintTarget $target, array $configuration, array $nativeAuthority): BlueprintStepAttempt
    {
        $blueprint = ProjectBlueprint::query()->create([
            'workspace_id' => $this->coreWorkspace->getKey(), 'created_by_user_id' => $this->actor->getKey(), 'name' => 'Fixture', 'latest_version' => 1,
        ]);
        $version = ProjectBlueprintVersion::query()->create([
            'project_blueprint_id' => $blueprint->getKey(), 'created_by_user_id' => $this->actor->getKey(), 'version' => 1,
            'definition' => ['environments' => [['key' => 'production', 'name' => 'Production', 'type' => 'production']], 'products' => ['monitor' => $configuration]],
            'definition_hash' => str_repeat('a', 64),
        ]);
        $runId = (string) Str::ulid();
        ProjectBlueprintRun::query()->create([
            'id' => $runId, 'workspace_id' => $this->coreWorkspace->getKey(), 'project_id' => $this->project->getKey(),
            'project_blueprint_version_id' => $version->getKey(), 'requested_by_user_id' => $this->actor->getKey(),
            'idempotency_key' => (string) Str::uuid(), 'intent_hash' => str_repeat('b', 64),
            'environment_bindings' => $target->environments, 'status' => 'processing',
        ]);
        $stepId = (string) Str::ulid();
        $payloadHash = BlueprintFingerprint::make([
            'step' => $stepId, 'target' => $target->toArray(), 'configuration' => $configuration, 'authority' => $nativeAuthority,
        ]);
        ProjectBlueprintStep::query()->create([
            'id' => $stepId, 'project_blueprint_run_id' => $runId, 'product' => 'monitor', 'target' => $target->toArray(),
            'configuration' => $configuration, 'native_authority' => $nativeAuthority,
            'core_binding_hash' => app(BlueprintAuthority::class)->bindingHash($target, 'monitor'), 'payload_hash' => $payloadHash,
            'status' => 'processing', 'generation' => 1, 'lease_token' => Str::random(64), 'lease_expires_at' => now()->addMinutes(10),
        ]);

        return ProjectBlueprintStep::query()->findOrFail($stepId)->attempt();
    }
}
