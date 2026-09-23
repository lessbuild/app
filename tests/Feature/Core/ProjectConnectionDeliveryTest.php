<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Services\Connections\DispatchDeploymentSucceededOutboxEvent;
use App\Core\Services\Connections\ProcessProjectConnectionDelivery;
use App\Core\Services\Connections\RetryProjectConnectionDeliveries;
use App\Modules\Deployer\Models\DeploymentSucceededOutboxEvent;
use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\ProjectConnectionEventReceipt;
use App\Modules\Monitor\Services\Connections\ConsumeDeploymentSucceeded;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProjectConnectionDeliveryTest extends TestCase
{
    private string $workspaceId;

    private string $projectId;

    private string $sourceResourceId;

    private string $targetResourceId;

    private string $sourceEnvironmentId;

    private string $targetEnvironmentId;

    private string $connectionId;

    private string $ownerUserId;

    private int $deployerEnvironmentId;

    private int $monitorEnvironmentId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.products.monitor.enabled' => true]);
        $this->bindEntitledPlans();
        $this->createCoreTables();
        $this->createDeployerTables();
        $this->createMonitorTables();
        $this->seedConnectedResources();
    }

    protected function tearDown(): void
    {
        foreach ([
            'project_connection_deliveries', 'project_connections', 'project_resources', 'project_environments',
            'project_products', 'project_memberships', 'workspace_product_access', 'workspace_memberships',
            'projects', 'workspaces', 'users',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        Schema::dropIfExists('deployment_succeeded_outbox_events');
        Schema::dropIfExists('environments');
        Schema::dropIfExists('builds');

        foreach ([
            'project_connection_event_receipts', 'deployments', 'releases', 'environments', 'applications', 'workspaces',
        ] as $table) {
            Schema::connection('monitor')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_deployer_outbox_delivers_a_deployment_to_monitor_once_and_keeps_a_receipt(): void
    {
        $event = $this->outboxEvent();
        $created = app(DispatchDeploymentSucceededOutboxEvent::class)->dispatch($event);

        $this->assertSame(1, $created);
        $delivery = ProjectConnectionDelivery::query()->sole();
        $this->assertSame($event->getKey(), $delivery->source_event_id);
        $this->assertSame((string) $this->monitorEnvironmentId, $delivery->payload['target_environment_id']);
        $this->assertSame((string) $this->deployerEnvironmentId, $delivery->payload['source_environment_id']);

        $status = app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey());
        $deployment = Deployment::query()->sole();

        $this->assertSame('delivered', $status);
        $this->assertSame('integration', $deployment->source);
        $this->assertSame($this->monitorEnvironmentId, $deployment->environment_id);
        $this->assertSame(str_repeat('c', 40), $deployment->commit_sha);
        $this->assertSame(str_repeat('c', 40), $deployment->release->version);
        $this->assertSame(1, ProjectConnectionEventReceipt::query()->count());
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('active', ProjectConnection::query()->findOrFail($this->connectionId)->status);

        $replayed = app(ConsumeDeploymentSucceeded::class)->handle(
            deliveryId: (string) $delivery->getKey(),
            connectionId: $this->connectionId,
            payload: $delivery->payload,
        );

        $this->assertSame($deployment->getKey(), $replayed->getKey());
        $this->assertSame(1, Deployment::query()->count());
        $this->assertSame(1, ProjectConnectionEventReceipt::query()->count());
    }

    public function test_deployments_without_an_active_core_mapping_are_consumed_without_retries(): void
    {
        $event = $this->outboxEvent();
        DB::connection('core')->table('project_resources')->where('id', $this->sourceResourceId)->update([
            'status' => 'inactive',
        ]);

        $created = app(DispatchDeploymentSucceededOutboxEvent::class)->dispatch($event);

        $this->assertSame(0, $created);
        $this->assertSame(0, ProjectConnectionDelivery::query()->count());
    }

    public function test_disconnected_connections_discard_queued_deliveries_without_writing_monitor_data(): void
    {
        $delivery = $this->makeDelivery();
        ProjectConnection::query()->whereKey($this->connectionId)->update([
            'status' => 'disconnected',
            'disconnected_at' => now(),
        ]);

        $status = app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey());

        $this->assertSame('discarded', $status);
        $this->assertSame('connection_disconnected', $delivery->fresh()->last_error_code);
        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame(0, ProjectConnectionEventReceipt::query()->count());
    }

    public function test_revoked_product_access_blocks_a_delivery_until_an_owner_retries_it(): void
    {
        $delivery = $this->makeDelivery();
        DB::connection('core')->table('workspace_product_access')->where('product', 'monitor')->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $status = app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey());

        $this->assertSame('blocked', $status);
        $this->assertSame('failed', ProjectConnection::query()->findOrFail($this->connectionId)->status);
        $this->assertSame(0, Deployment::query()->count());
        $this->assertSame(0, ProjectConnectionEventReceipt::query()->count());

        DB::connection('core')->table('workspace_product_access')->where('product', 'monitor')->update([
            'status' => 'active',
            'revoked_at' => null,
        ]);
        $retried = app(RetryProjectConnectionDeliveries::class)->handle(
            PlatformUser::query()->findOrFail($this->ownerUserId),
            Project::query()->findOrFail($this->projectId),
            ProjectConnection::query()->findOrFail($this->connectionId),
        );

        $this->assertSame(1, $retried);
        $this->assertSame('delivered', app(ProcessProjectConnectionDelivery::class)->process((string) $delivery->getKey()));
        $this->assertSame(1, Deployment::query()->count());
        $this->assertSame(1, ProjectConnectionEventReceipt::query()->count());
    }

    private function outboxEvent(): DeploymentSucceededOutboxEvent
    {
        $buildId = DB::table('builds')->insertGetId([
            'environment_id' => $this->deployerEnvironmentId,
            'status' => 'succeeded',
            'revision' => str_repeat('c', 40),
            'release_name' => null,
            'built_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DeploymentSucceededOutboxEvent::query()->create([
            'event_type' => DeploymentSucceededOutboxEvent::EVENT_TYPE,
            'event_version' => 1,
            'source_build_id' => $buildId,
            'source_project_id' => 91,
            'source_environment_id' => $this->deployerEnvironmentId,
            'payload' => [
                'deployment_id' => (string) Str::uuid(),
                'version' => str_repeat('c', 40),
                'revision' => str_repeat('c', 40),
                'deployed_at' => now()->subMinutes(2)->utc()->toIso8601String(),
            ],
            'status' => 'pending',
            'available_at' => now(),
        ]);
    }

    private function makeDelivery(): ProjectConnectionDelivery
    {
        return ProjectConnectionDelivery::query()->create([
            'project_connection_id' => $this->connectionId,
            'source_event_id' => (string) Str::ulid(),
            'event_type' => DeploymentSucceededOutboxEvent::EVENT_TYPE,
            'event_version' => 1,
            'payload' => [
                'deployment_id' => (string) Str::uuid(),
                'version' => str_repeat('d', 40),
                'revision' => str_repeat('d', 40),
                'deployed_at' => now()->subMinutes(2)->utc()->toIso8601String(),
                'source_project_id' => '91',
                'source_environment_id' => (string) $this->deployerEnvironmentId,
                'source_build_id' => '15',
                'canonical_project_id' => $this->projectId,
                'canonical_environment_id' => $this->sourceEnvironmentId,
                'target_environment_id' => (string) $this->monitorEnvironmentId,
            ],
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }

    private function seedConnectedResources(): void
    {
        $this->workspaceId = (string) Str::ulid();
        $this->projectId = (string) Str::ulid();
        $this->sourceResourceId = (string) Str::ulid();
        $this->targetResourceId = (string) Str::ulid();
        $this->sourceEnvironmentId = (string) Str::ulid();
        $this->targetEnvironmentId = (string) Str::ulid();
        $this->connectionId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();
        $this->ownerUserId = (string) Str::ulid();

        DB::connection('core')->table('users')->insert([
            'id' => $this->ownerUserId,
            'name' => 'Workspace owner',
            'email' => 'owner@example.test',
            'email_normalized' => 'owner@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deployerEnvironmentId = DB::table('environments')->insertGetId([
            'project_id' => 91,
            'name' => 'Production',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $monitorWorkspaceId = DB::connection('monitor')->table('workspaces')->insertGetId([
            'name' => 'Monitor workspace',
            'slug' => 'monitor-workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $monitorApplicationId = DB::connection('monitor')->table('applications')->insertGetId([
            'workspace_id' => $monitorWorkspaceId,
            'name' => 'Monitor application',
            'slug' => 'monitor-application',
            'status' => 'active',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->monitorEnvironmentId = DB::connection('monitor')->table('environments')->insertGetId([
            'application_id' => $monitorApplicationId,
            'name' => 'Production',
            'slug' => 'production',
            'status' => 'active',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'status' => 'active',
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->ownerUserId,
            'role' => 'owner',
            'status' => 'active',
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['deployer', 'monitor'] as $product) {
            DB::connection('core')->table('workspace_product_access')->insert([
                'id' => (string) Str::ulid(),
                'membership_id' => $membershipId,
                'product' => $product,
                'status' => 'active',
                'expires_at' => null,
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('core')->table('project_products')->insert([
                'id' => (string) Str::ulid(),
                'project_id' => $this->projectId,
                'product' => $product,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::connection('core')->table('projects')->insert([
            'id' => $this->projectId,
            'workspace_id' => $this->workspaceId,
            'name' => 'Shared Project',
            'status' => 'active',
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'user_id' => $this->ownerUserId,
            'role' => 'owner',
            'status' => 'active',
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_environments')->insert([
            ['id' => $this->sourceEnvironmentId, 'project_id' => $this->projectId, 'name' => 'Production', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->targetEnvironmentId, 'project_id' => $this->projectId, 'name' => 'Production Monitor', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::connection('core')->table('project_resources')->insert([
            [
                'id' => $this->sourceResourceId,
                'project_id' => $this->projectId,
                'environment_id' => $this->sourceEnvironmentId,
                'product' => 'deployer',
                'resource_type' => 'environment',
                'resource_id' => (string) $this->deployerEnvironmentId,
                'name' => 'Deployer production',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->targetResourceId,
                'project_id' => $this->projectId,
                'environment_id' => $this->targetEnvironmentId,
                'product' => 'monitor',
                'resource_type' => 'environment',
                'resource_id' => (string) $this->monitorEnvironmentId,
                'name' => 'Monitor production',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::ulid(),
                'project_id' => $this->projectId,
                'environment_id' => null,
                'product' => 'deployer',
                'resource_type' => 'project',
                'resource_id' => '91',
                'name' => 'Deployer project',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::connection('core')->table('project_connections')->insert([
            'id' => $this->connectionId,
            'project_id' => $this->projectId,
            'source_resource_id' => $this->sourceResourceId,
            'target_resource_id' => $this->targetResourceId,
            'source_environment_id' => $this->sourceEnvironmentId,
            'target_environment_id' => $this->targetEnvironmentId,
            'capabilities' => json_encode(['deployment_context'], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'created_by_user_id' => null,
            'last_succeeded_at' => null,
            'last_error_code' => null,
            'last_error_at' => null,
            'disconnected_at' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function bindEntitledPlans(): void
    {
        app()->instance(ProductPlanResolver::class, new class implements ProductPlanResolver
        {
            public function resolve(string $workspaceId, ProductKey $product): ProductPlanResolution
            {
                return new ProductPlanResolution(
                    product: $product,
                    workspaceId: $workspaceId,
                    available: true,
                    planKey: $product->value,
                    subscriptionStatus: 'active',
                    entitlements: $product === ProductKey::Deployer ? ['monitoring'] : [],
                    limits: $product === ProductKey::Monitor ? ['deployment_context_minutes' => 60] : [],
                );
            }
        });
    }

    private function createCoreTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('email_normalized')->unique();
            $table->string('password');
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('workspace_id', 26);
            $table->string('user_id', 26);
            $table->string('role');
            $table->string('status');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('membership_id', 26);
            $table->string('product');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('workspace_id', 26);
            $table->string('name');
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('user_id', 26);
            $table->string('role');
            $table->string('status');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('product');
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('project_environments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('name');
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('project_resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('environment_id', 26)->nullable();
            $table->string('product');
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('name')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::connection('core')->create('project_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_id', 26);
            $table->string('source_resource_id', 26);
            $table->string('target_resource_id', 26);
            $table->string('source_environment_id', 26)->nullable();
            $table->string('target_environment_id', 26)->nullable();
            $table->json('capabilities');
            $table->string('status');
            $table->string('created_by_user_id', 26)->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_connection_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_connection_id', 26);
            $table->string('source_event_id', 26);
            $table->string('event_type');
            $table->unsignedSmallInteger('event_version');
            $table->json('payload');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
            $table->unique(['source_event_id', 'project_connection_id']);
        });
    }

    private function createDeployerTables(): void
    {
        Schema::create('environments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('builds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('environment_id')->nullable();
            $table->string('status');
            $table->string('revision', 64)->nullable();
            $table->string('release_name')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('deployment_succeeded_outbox_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_type');
            $table->unsignedSmallInteger('event_version');
            $table->unsignedBigInteger('source_build_id');
            $table->unsignedBigInteger('source_project_id');
            $table->unsignedBigInteger('source_environment_id');
            $table->json('payload');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });
    }

    private function createMonitorTables(): void
    {
        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });
        Schema::connection('monitor')->create('applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id');
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('environments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id');
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->unsignedBigInteger('event_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('monitor')->create('releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id');
            $table->string('service', 100)->nullable();
            $table->string('service_namespace', 100)->nullable();
            $table->string('version', 128);
            $table->char('service_hash', 64);
            $table->char('version_hash', 64);
            $table->timestamp('first_seen_at', 6)->nullable();
            $table->timestamp('last_seen_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['application_id', 'service_hash', 'version_hash']);
        });
        Schema::connection('monitor')->create('deployments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id');
            $table->foreignId('release_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('ingest_token_id')->nullable();
            $table->uuid('deployment_key');
            $table->char('payload_hash', 64);
            $table->string('source', 16);
            $table->string('commit_sha', 64)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('deployed_at', 6);
            $table->timestamps(6);
            $table->unique(['environment_id', 'deployment_key']);
        });
        Schema::connection('monitor')->create('project_connection_event_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id', 26);
            $table->string('project_connection_id', 26);
            $table->string('handler', 100);
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('deployment_id');
            $table->timestamp('processed_at', 6);
            $table->timestamps(6);
            $table->unique(['delivery_id', 'handler']);
        });
    }
}

final class ProjectConnectionDeliveryTestPlanResolver implements ProductPlanResolver
{
    public function resolve(string $workspaceId, ProductKey $product): ProductPlanResolution
    {
        return new ProductPlanResolution(
            product: $product,
            workspaceId: $workspaceId,
            available: true,
            planKey: $product->value,
            subscriptionStatus: 'active',
            entitlements: $product === ProductKey::Deployer ? ['monitoring'] : [],
            limits: $product === ProductKey::Monitor ? ['deployment_context_minutes' => 60] : [],
        );
    }
}
