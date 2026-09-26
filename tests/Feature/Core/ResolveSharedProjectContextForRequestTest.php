<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Data\Projects\ProductProjectContextState;
use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\ProjectResourceDestinationRegistry;
use App\Core\Services\ResolveSharedProjectContextForRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResolveSharedProjectContextForRequestTest extends TestCase
{
    private PlatformUser $user;

    private string $membershipId;

    private string $workspaceId;

    private string $projectId;

    private string $environmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->seedProject();
    }

    protected function tearDown(): void
    {
        foreach ([
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

    public function test_it_accepts_a_mapped_project_environment_for_an_authorized_product_user(): void
    {
        $this->registerDestinations('monitor');
        $request = $this->request([
            'context_project' => $this->projectId,
            'context_environment' => $this->environmentId,
        ]);

        $context = app(ResolveSharedProjectContextForRequest::class)->handle(
            $request,
            'monitor',
            'environment',
            'monitor-environment-41',
        );

        $this->assertSame(ProductProjectContextState::Available, $context->state);
        $this->assertSame($this->projectId, $context->project?->getKey());
        $this->assertSame($this->environmentId, $context->environment?->environment?->getKey());
        $this->assertStringContainsString('context_project='.$this->projectId, $context->productUrlOverrides['monitor']);
        $this->assertStringContainsString('context_environment='.$this->environmentId, $context->productUrlOverrides['monitor']);
    }

    public function test_it_rejects_context_when_the_workspace_product_grant_was_revoked(): void
    {
        $this->registerDestinations('monitor');
        DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->membershipId)
            ->where('product', 'monitor')
            ->update(['revoked_at' => now()]);

        $context = app(ResolveSharedProjectContextForRequest::class)->handle(
            $this->request([
                'context_project' => $this->projectId,
                'context_environment' => $this->environmentId,
            ]),
            'monitor',
            'environment',
            'monitor-environment-41',
        );

        $this->assertSame(ProductProjectContextState::Unavailable, $context->state);
        $this->assertSame([], $context->productUrlOverrides);
    }

    public function test_it_rejects_an_environment_from_another_project_or_a_different_local_resource(): void
    {
        $this->registerDestinations('monitor');
        $context = app(ResolveSharedProjectContextForRequest::class)->handle(
            $this->request([
                'context_project' => $this->projectId,
                'context_environment' => (string) Str::ulid(),
            ]),
            'monitor',
            'environment',
            'monitor-environment-41',
        );
        $wrongResource = app(ResolveSharedProjectContextForRequest::class)->handle(
            $this->request([
                'context_project' => $this->projectId,
                'context_environment' => $this->environmentId,
            ]),
            'monitor',
            'environment',
            'monitor-environment-elsewhere',
        );

        $this->assertSame(ProductProjectContextState::Unavailable, $context->state);
        $this->assertSame(ProductProjectContextState::Unavailable, $wrongResource->state);
    }

    public function test_deployer_environment_context_requires_the_environment_and_project_urls_to_share_a_resource_scope(): void
    {
        DB::connection('core')->table('workspace_product_access')->insert($this->productGrant('deployer'));
        DB::connection('core')->table('project_products')->insert($this->projectProduct('deployer'));
        $projectResourceId = (string) Str::ulid();
        $environmentResourceId = (string) Str::ulid();
        $this->resource($projectResourceId, 'deployer', 'project', '51', null);
        $this->resource($environmentResourceId, 'deployer', 'environment', '51', $this->environmentId);
        $this->registerDestinations('deployer');

        $context = app(ResolveSharedProjectContextForRequest::class)->handle(
            $this->request([
                'context_project' => $this->projectId,
                'context_environment' => $this->environmentId,
            ]),
            'deployer',
            'project',
            '51',
        );

        $this->assertSame(ProductProjectContextState::Available, $context->state);
        $this->assertStringContainsString('environment-', $context->productUrlOverrides['deployer']);
    }

    public function test_a_request_without_shared_context_does_not_resolve_or_warn(): void
    {
        $context = app(ResolveSharedProjectContextForRequest::class)->handle(
            Request::create('/dashboard'),
            'monitor',
            null,
            null,
        );

        $this->assertSame(ProductProjectContextState::None, $context->state);
    }

    public function test_the_signal_topbar_shows_a_warning_for_unavailable_shared_context(): void
    {
        $html = Blade::render('<x-signal.layouts.topbar product-key="monitor" :shared-context-unavailable="true" />');

        $this->assertStringContainsString('Shared project context could not be verified', $html);
        $this->assertStringContainsString('role="status"', $html);
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
            $table->timestamp('expires_at')->nullable();
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
            $table->json('metadata')->nullable();
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
    }

    private function seedProject(): void
    {
        $userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->membershipId = (string) Str::ulid();
        $this->projectId = (string) Str::ulid();
        $this->environmentId = (string) Str::ulid();
        $now = now();
        $this->user = (new PlatformUser)->forceFill(['id' => $userId, 'status' => 'active', 'name' => 'Owner']);

        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $userId,
            'name' => 'Workspace',
            'slug' => 'workspace',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $this->membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('workspace_product_access')->insert($this->productGrant('monitor'));
        DB::connection('core')->table('projects')->insert([
            'id' => $this->projectId,
            'workspace_id' => $this->workspaceId,
            'name' => 'Storefront',
            'slug' => 'storefront',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'user_id' => $userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('core')->table('project_products')->insert($this->projectProduct('monitor'));
        DB::connection('core')->table('project_environments')->insert([
            'id' => $this->environmentId,
            'project_id' => $this->projectId,
            'name' => 'Production',
            'slug' => 'production',
            'environment_type' => 'production',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->resource((string) Str::ulid(), 'monitor', 'environment', 'monitor-environment-41', $this->environmentId);
    }

    /** @return array<string, mixed> */
    private function productGrant(string $product): array
    {
        return [
            'id' => (string) Str::ulid(),
            'membership_id' => $this->membershipId,
            'product' => $product,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function projectProduct(string $product): array
    {
        return [
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'product' => $product,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function resource(string $id, string $product, string $type, string $resourceId, ?string $environmentId): void
    {
        DB::connection('core')->table('project_resources')->insert([
            'id' => $id,
            'project_id' => $this->projectId,
            'environment_id' => $environmentId,
            'product' => $product,
            'resource_type' => $type,
            'resource_id' => $resourceId,
            'name' => 'Mapped resource',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function registerDestinations(string $product): void
    {
        $provider = new class($product) implements ProjectResourceDestinationProvider
        {
            public function __construct(private readonly string $product) {}

            /** @param Collection<int, ProjectResource> $resources
             * @return array<string, ProjectResourceDestination>
             */
            public function destinations(PlatformUser $user, Collection $resources): array
            {
                return $resources->mapWithKeys(function (ProjectResource $resource): array {
                    $url = match ([$this->product, $resource->resource_type]) {
                        ['monitor', 'environment'] => 'https://monitor.example/environments/41',
                        ['deployer', 'project'] => 'https://deployer.example/projects/51?tab=overview#project',
                        ['deployer', 'environment'] => 'https://deployer.example/projects/51?tab=deployments#environment-'.$resource->environment_id,
                        default => null,
                    };

                    return [(string) $resource->getKey() => new ProjectResourceDestination(
                        $url === null ? ProjectResourceDestinationState::Unavailable : ProjectResourceDestinationState::Available,
                        $url,
                    )];
                })->all();
            }
        };

        app(ProjectResourceDestinationRegistry::class)->register($product, $provider);
    }

    /** @param array<string, string> $query */
    private function request(array $query): Request
    {
        $request = Request::create('/dashboard', 'GET', $query);
        $request->setUserResolver(fn (): PlatformUser => $this->user);

        return $request;
    }
}
