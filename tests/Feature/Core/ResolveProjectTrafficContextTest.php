<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Contracts\ProjectTrafficContextProvider;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Data\Projects\ProjectTrafficWindowSummary;
use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Services\Connections\ProjectConnectionEntitlementPolicy;
use App\Core\Services\ProjectTrafficContextRegistry;
use App\Core\Services\ResolveProjectTrafficContext;
use App\Core\Services\WorkspaceProjectAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResolveProjectTrafficContextTest extends TestCase
{
    private PlatformUser $user;

    private string $workspaceId;

    private string $membershipId;

    private string $projectId;

    private string $sourceResourceId;

    private string $targetResourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->seedProject();
        $this->bindPlans();
    }

    protected function tearDown(): void
    {
        foreach ([
            'project_connections',
            'project_resources',
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

    public function test_it_resolves_only_connected_traffic_aggregates_with_the_monitor_plan_window(): void
    {
        $provider = new class implements ProjectTrafficContextProvider
        {
            /** @var list<array{from: CarbonImmutable, until: CarbonImmutable, resource: string}> */
            public array $windows = [];

            public function aggregate(
                PlatformUser $user,
                Project $project,
                ProjectResource $resource,
                CarbonImmutable $from,
                CarbonImmutable $until,
            ): ?ProjectTrafficWindowSummary {
                $this->windows[] = ['from' => $from, 'until' => $until, 'resource' => (string) $resource->resource_id];

                return new ProjectTrafficWindowSummary(pageviews: (3 - count($this->windows)) * 10, visitors: count($this->windows));
            }
        };
        $contexts = $this->resolver($provider)->forMonitorEnvironment($this->user, 'monitor-env-1', CarbonImmutable::parse('2026-04-02 12:00:00', 'UTC'));

        $this->assertCount(1, $contexts);
        $this->assertSame('Storefront', $contexts->first()->projectName);
        $this->assertSame('Analytics site', $contexts->first()->siteName);
        $this->assertSame(60, $contexts->first()->windowMinutes);
        $this->assertSame(20, $contexts->first()->incidentWindow->pageviews);
        $this->assertSame(10, $contexts->first()->previousWindow->pageviews);
        $this->assertSame('2026-04-02T11:00:00+00:00', $provider->windows[0]['from']->toIso8601String());
        $this->assertSame('2026-04-02T12:00:00+00:00', $provider->windows[0]['until']->toIso8601String());
        $this->assertSame('2026-04-02T10:00:00+00:00', $provider->windows[1]['from']->toIso8601String());
        $this->assertSame('2026-04-02T11:00:00+00:00', $provider->windows[1]['until']->toIso8601String());
        $this->assertSame(['analytics-site-1', 'analytics-site-1'], array_column($provider->windows, 'resource'));
    }

    public function test_it_returns_no_context_after_analytics_product_access_is_revoked(): void
    {
        DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->membershipId)
            ->where('product', 'analytics')
            ->update(['revoked_at' => now()]);
        $provider = new class implements ProjectTrafficContextProvider
        {
            public int $calls = 0;

            public function aggregate(
                PlatformUser $user,
                Project $project,
                ProjectResource $resource,
                CarbonImmutable $from,
                CarbonImmutable $until,
            ): ?ProjectTrafficWindowSummary {
                $this->calls++;

                return new ProjectTrafficWindowSummary(pageviews: 1, visitors: 1);
            }
        };

        $result = $this->resolver($provider)->forMonitorEnvironment($this->user, 'monitor-env-1', now());

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $provider->calls);
    }

    private function resolver(ProjectTrafficContextProvider $provider): ResolveProjectTrafficContext
    {
        $registry = new ProjectTrafficContextRegistry;
        $registry->register('analytics', $provider);

        return new ResolveProjectTrafficContext(
            app(WorkspaceProjectAccess::class),
            app(ProjectConnectionEntitlementPolicy::class),
            $registry,
        );
    }

    private function bindPlans(): void
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
                    entitlements: $product === ProductKey::Analytics ? ['traffic_context'] : ['*'],
                    limits: $product === ProductKey::Monitor ? ['deployment_context_minutes' => 60] : [],
                );
            }
        });
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
    }

    private function seedProject(): void
    {
        $userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->membershipId = (string) Str::ulid();
        $this->projectId = (string) Str::ulid();
        $this->sourceResourceId = (string) Str::ulid();
        $this->targetResourceId = (string) Str::ulid();
        $this->user = (new PlatformUser)->forceFill(['id' => $userId, 'status' => 'active', 'name' => 'Owner']);

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
            'id' => $this->membershipId,
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
            'name' => 'Storefront',
            'slug' => 'storefront',
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

        foreach (['analytics', 'monitor'] as $product) {
            DB::connection('core')->table('workspace_product_access')->insert([
                'id' => (string) Str::ulid(),
                'membership_id' => $this->membershipId,
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
            [$this->sourceResourceId, 'analytics', 'site', 'analytics-site-1', 'Analytics site'],
            [$this->targetResourceId, 'monitor', 'environment', 'monitor-env-1', 'Monitor environment'],
        ] as [$id, $product, $type, $resourceId, $name]) {
            DB::connection('core')->table('project_resources')->insert([
                'id' => $id,
                'project_id' => $this->projectId,
                'product' => $product,
                'resource_type' => $type,
                'resource_id' => $resourceId,
                'name' => $name,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::connection('core')->table('project_connections')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'source_resource_id' => $this->sourceResourceId,
            'target_resource_id' => $this->targetResourceId,
            'capabilities' => json_encode(['traffic_context']),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
