<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Services\Projects\CreateProjectConnection;
use App\Core\Services\Projects\DisconnectProjectConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ProjectConnectionsTest extends TestCase
{
    private PlatformUser $user;

    private string $workspaceId;

    private string $projectId;

    private string $deployerResourceId;

    private string $monitorResourceId;

    private string $analyticsResourceId;

    private string $deployerEnvironmentId;

    private string $monitorEnvironmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->bindEntitledPlans();
        $this->seedProject();
    }

    protected function tearDown(): void
    {
        foreach ([
            'project_connection_events',
            'project_connections',
            'project_resources',
            'project_environments',
            'project_products',
            'project_memberships',
            'workspace_product_access',
            'workspace_memberships',
            'projects',
            'workspaces',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_owner_can_connect_authorized_resources_with_a_supported_behavior(): void
    {
        $connection = app(CreateProjectConnection::class)->handle(
            user: $this->user,
            project: Project::query()->findOrFail($this->projectId),
            sourceResourceId: $this->deployerResourceId,
            targetResourceId: $this->monitorResourceId,
            capabilities: ['deployment_context'],
        );

        $this->assertInstanceOf(ProjectConnection::class, $connection);
        $this->assertSame($this->projectId, $connection->project_id);
        $this->assertSame('pending', $connection->status);
        $this->assertSame(['deployment_context'], $connection->capabilities);
        $this->assertSame($this->user->getKey(), $connection->created_by_user_id);
        $this->assertDatabaseHas('project_connection_events', [
            'project_connection_id' => $connection->getKey(),
            'actor_user_id' => $this->user->getKey(),
            'event_type' => 'created',
        ], 'core');
    }

    public function test_owner_can_connect_a_deployer_environment_to_an_analytics_site(): void
    {
        $connection = app(CreateProjectConnection::class)->handle(
            user: $this->user,
            project: Project::query()->findOrFail($this->projectId),
            sourceResourceId: $this->deployerResourceId,
            targetResourceId: $this->analyticsResourceId,
            capabilities: ['release_annotations'],
        );

        $this->assertSame(['release_annotations'], $connection->capabilities);
        $this->assertNull($connection->target_environment_id);
        $this->assertSame('pending', $connection->status);
    }

    public function test_connection_rejects_unavailable_behavior_and_same_app_resources(): void
    {
        $project = Project::query()->findOrFail($this->projectId);

        try {
            app(CreateProjectConnection::class)->handle(
                user: $this->user,
                project: $project,
                sourceResourceId: $this->deployerResourceId,
                targetResourceId: $this->analyticsResourceId,
                capabilities: ['deployment_context'],
            );
            $this->fail('An unsupported behavior should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capabilities', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        app(CreateProjectConnection::class)->handle(
            user: $this->user,
            project: $project,
            sourceResourceId: $this->deployerResourceId,
            targetResourceId: $this->deployerResourceId,
            capabilities: ['deployment_context'],
        );
    }

    public function test_every_end_requires_current_product_access(): void
    {
        DB::connection('core')->table('workspace_product_access')
            ->where('product', 'monitor')
            ->update(['revoked_at' => now()]);

        $this->expectException(AuthorizationException::class);
        app(CreateProjectConnection::class)->handle(
            user: $this->user,
            project: Project::query()->findOrFail($this->projectId),
            sourceResourceId: $this->deployerResourceId,
            targetResourceId: $this->monitorResourceId,
            capabilities: ['deployment_context'],
        );
    }

    public function test_deployment_context_requires_both_product_plan_entitlements(): void
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
                    limits: $product === ProductKey::Monitor ? ['deployment_context_minutes' => 0] : [],
                );
            }
        });

        $this->expectException(ValidationException::class);
        app(CreateProjectConnection::class)->handle(
            user: $this->user,
            project: Project::query()->findOrFail($this->projectId),
            sourceResourceId: $this->deployerResourceId,
            targetResourceId: $this->monitorResourceId,
            capabilities: ['deployment_context'],
        );
    }

    public function test_release_annotations_require_the_deployer_plan_entitlement(): void
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
                    entitlements: $product === ProductKey::Deployer ? ['monitoring'] : ['*'],
                );
            }
        });

        $this->expectException(ValidationException::class);
        app(CreateProjectConnection::class)->handle(
            user: $this->user,
            project: Project::query()->findOrFail($this->projectId),
            sourceResourceId: $this->deployerResourceId,
            targetResourceId: $this->analyticsResourceId,
            capabilities: ['release_annotations'],
        );
    }

    public function test_release_annotations_require_the_analytics_plan_entitlement(): void
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
                    entitlements: $product === ProductKey::Deployer ? ['monitoring', 'releases'] : [],
                );
            }
        });

        $this->expectException(ValidationException::class);
        app(CreateProjectConnection::class)->handle(
            user: $this->user,
            project: Project::query()->findOrFail($this->projectId),
            sourceResourceId: $this->deployerResourceId,
            targetResourceId: $this->analyticsResourceId,
            capabilities: ['release_annotations'],
        );
    }

    public function test_disconnection_retains_the_mapping_and_reconnection_resets_it_to_pending(): void
    {
        $creator = app(CreateProjectConnection::class);
        $project = Project::query()->findOrFail($this->projectId);
        $connection = $creator->handle(
            $this->user,
            $project,
            $this->deployerResourceId,
            $this->monitorResourceId,
            ['deployment_context'],
        );

        $disconnected = app(DisconnectProjectConnection::class)->handle($this->user, $project, $connection);

        $this->assertSame('disconnected', $disconnected->status);
        $this->assertNotNull($disconnected->disconnected_at);
        $this->assertDatabaseHas('project_connections', [
            'id' => $connection->getKey(),
            'status' => 'disconnected',
        ], 'core');

        $reconnected = $creator->handle(
            $this->user,
            $project,
            $this->deployerResourceId,
            $this->monitorResourceId,
            ['deployment_context'],
        );

        $this->assertSame($connection->getKey(), $reconnected->getKey());
        $this->assertSame('pending', $reconnected->status);
        $this->assertNull($reconnected->disconnected_at);
        $eventTypes = DB::connection('core')->table('project_connection_events')
            ->where('project_connection_id', $connection->getKey())
            ->pluck('event_type')
            ->all();
        sort($eventTypes);

        $this->assertSame(['created', 'disconnected', 'reconnected'], $eventTypes);
    }

    private function createTables(): void
    {
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('owner_user_id', 26);
            $table->string('name');
            $table->string('slug');
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
            $table->string('role');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('workspace_id', 26);
            $table->string('name');
            $table->string('slug');
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
            $table->string('slug');
            $table->string('environment_type');
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
            $table->unique(['source_resource_id', 'target_resource_id']);
        });
        Schema::connection('core')->create('project_connection_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('project_connection_id', 26);
            $table->string('actor_user_id', 26)->nullable();
            $table->string('event_type', 40);
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    private function seedProject(): void
    {
        $userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();
        $this->projectId = (string) Str::ulid();
        $this->deployerResourceId = (string) Str::ulid();
        $this->monitorResourceId = (string) Str::ulid();
        $this->analyticsResourceId = (string) Str::ulid();
        $this->deployerEnvironmentId = (string) Str::ulid();
        $this->monitorEnvironmentId = (string) Str::ulid();

        $this->user = (new PlatformUser)->forceFill([
            'id' => $userId,
            'status' => 'active',
            'name' => 'Workspace owner',
            'email' => 'owner@example.test',
        ]);

        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $userId,
            'name' => 'Workspace',
            'slug' => 'workspace',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('projects')->insert([
            'id' => $this->projectId,
            'workspace_id' => $this->workspaceId,
            'name' => 'Shared Project',
            'slug' => 'shared-project',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'user_id' => $userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['deployer', 'monitor', 'analytics'] as $product) {
            DB::connection('core')->table('workspace_product_access')->insert([
                'id' => (string) Str::ulid(),
                'membership_id' => $membershipId,
                'product' => $product,
                'role' => 'owner',
                'status' => 'active',
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

        foreach ([
            [$this->deployerEnvironmentId, 'Production'],
            [$this->monitorEnvironmentId, 'Production'],
        ] as [$id, $name]) {
            DB::connection('core')->table('project_environments')->insert([
                'id' => $id,
                'project_id' => $this->projectId,
                'name' => $name,
                'slug' => strtolower($name),
                'environment_type' => 'production',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([
            [$this->deployerResourceId, $this->deployerEnvironmentId, 'deployer', 'Deployer environment'],
            [$this->monitorResourceId, $this->monitorEnvironmentId, 'monitor', 'Monitor environment'],
            [$this->analyticsResourceId, null, 'analytics', 'Analytics site'],
        ] as [$id, $environmentId, $product, $name]) {
            DB::connection('core')->table('project_resources')->insert([
                'id' => $id,
                'project_id' => $this->projectId,
                'environment_id' => $environmentId,
                'product' => $product,
                'resource_type' => $product === 'analytics' ? 'site' : 'environment',
                'resource_id' => Str::ulid(),
                'name' => $name,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
                    entitlements: $product === ProductKey::Deployer ? ['monitoring', 'releases'] : ['*'],
                    limits: $product === ProductKey::Monitor ? ['deployment_context_minutes' => 60] : [],
                );
            }
        });
    }
}
