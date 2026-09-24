<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\PlatformUser;
use App\Core\Services\ProjectResourceDestinationRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceWorkflowActivityTest extends TestCase
{
    private string $userId;

    private string $workspaceId;

    private string $projectId;

    private string $membershipId;

    private object $destinationStates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->createTables();

        $this->userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->projectId = (string) Str::ulid();
        $this->membershipId = (string) Str::ulid();
        $this->destinationStates = (object) [
            'deployer' => ProjectResourceDestinationState::Available,
            'monitor' => ProjectResourceDestinationState::Available,
        ];

        DB::connection('core')->table('users')->insert([
            'id' => $this->userId,
            'name' => 'Taylor Owner',
            'email' => 'taylor@example.test',
            'email_normalized' => 'taylor@example.test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspaces')->insert([
            'id' => $this->workspaceId,
            'owner_user_id' => $this->userId,
            'name' => 'Northstar Studio',
            'slug' => 'northstar-studio',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $this->membershipId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['deployer', 'monitor'] as $product) {
            DB::connection('core')->table('workspace_product_access')->insert([
                'id' => (string) Str::ulid(),
                'membership_id' => $this->membershipId,
                'product' => $product,
                'role' => 'owner',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::connection('core')->table('projects')->insert([
            'id' => $this->projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->userId,
            'name' => 'Checkout app',
            'slug' => 'checkout-app',
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['deployer', 'monitor'] as $product) {
            DB::connection('core')->table('project_products')->insert([
                'id' => (string) Str::ulid(),
                'project_id' => $this->projectId,
                'product' => $product,
                'status' => 'active',
                'metadata' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $sourceResourceId = (string) Str::ulid();
        $targetResourceId = (string) Str::ulid();
        foreach ([
            [$sourceResourceId, 'deployer', 'Production environment'],
            [$targetResourceId, 'monitor', 'Production checks'],
        ] as [$resourceId, $product, $name]) {
            DB::connection('core')->table('project_resources')->insert([
                'id' => $resourceId,
                'project_id' => $this->projectId,
                'product' => $product,
                'resource_type' => 'environment',
                'resource_id' => 'local-'.$product.'-production',
                'name' => $name,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $connectionId = (string) Str::ulid();
        DB::connection('core')->table('project_connections')->insert([
            'id' => $connectionId,
            'project_id' => $this->projectId,
            'source_resource_id' => $sourceResourceId,
            'target_resource_id' => $targetResourceId,
            'capabilities' => json_encode(['deployment_context']),
            'status' => 'failed',
            'last_error_code' => 'provider_secret_must_not_render',
            'last_error_at' => now()->subMinute(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subMinute(),
        ]);
        DB::connection('core')->table('project_connection_deliveries')->insert([
            'id' => (string) Str::ulid(),
            'project_connection_id' => $connectionId,
            'source_event_id' => (string) Str::ulid(),
            'event_type' => 'deployer.deployment_succeeded',
            'event_version' => 1,
            'payload' => json_encode(['version' => 'v12', 'deployed_at' => now()->subMinutes(5)->toIso8601String()]),
            'status' => 'failed',
            'attempts' => 3,
            'available_at' => null,
            'last_attempted_at' => now()->subMinute(),
            'delivered_at' => null,
            'last_error_code' => 'provider_secret_must_not_render',
            'last_error_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);

        $registry = app(ProjectResourceDestinationRegistry::class);
        foreach (['deployer', 'monitor'] as $product) {
            $registry->register($product, new class($product, $this->destinationStates) implements ProjectResourceDestinationProvider
            {
                public function __construct(private readonly string $product, private readonly object $states) {}

                public function destinations(PlatformUser $user, Collection $resources): array
                {
                    return $resources->mapWithKeys(fn ($resource): array => [
                        (string) $resource->getKey() => new ProjectResourceDestination($this->states->{$this->product}),
                    ])->all();
                }
            });
        }

        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        foreach ([
            'project_connection_deliveries',
            'project_connections',
            'project_resources',
            'project_products',
            'project_memberships',
            'projects',
            'workspace_product_access',
            'workspace_memberships',
            'workspaces',
            'users',
        ] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }

        parent::tearDown();
    }

    private function createTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status', 24)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 24);
            $table->string('status', 24);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product', 24);
            $table->string('role', 24);
            $table->string('status', 24);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('created_by_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status', 24);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 24);
            $table->string('status', 24);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_products', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->string('product', 24);
            $table->string('status', 24);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_resources', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('environment_id', 26)->nullable();
            $table->string('product', 24);
            $table->string('resource_type', 100);
            $table->string('resource_id', 191);
            $table->string('name')->nullable();
            $table->string('status', 24);
            $table->timestamps();
        });
        Schema::connection('core')->create('project_connections', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('source_resource_id', 26)->nullable();
            $table->char('target_resource_id', 26)->nullable();
            $table->char('source_environment_id', 26)->nullable();
            $table->char('target_environment_id', 26)->nullable();
            $table->json('capabilities')->nullable();
            $table->string('status', 24);
            $table->timestamp('last_succeeded_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('automation_paused_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_connection_deliveries', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_connection_id', 26);
            $table->string('source_event_id', 191);
            $table->string('event_type', 100);
            $table->unsignedSmallInteger('event_version');
            $table->json('payload');
            $table->string('status', 24);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_workflow_activity_persists_steps_and_offers_only_the_failed_step_for_retry(): void
    {
        $user = PlatformUser::query()->findOrFail($this->userId);
        $response = $this->actingAs($user, 'platform')
            ->get(route('core.workspace.workflows', $this->workspaceId));

        $response
            ->assertOk()
            ->assertSeeText('Workflow activity')
            ->assertSeeText('Deployment v12 succeeded')
            ->assertSeeText('Deployment context recorded')
            ->assertSeeText('Project: Checkout app')
            ->assertSeeText('Retry this step')
            ->assertDontSeeText('provider_secret_must_not_render');
        $response->assertSee(route('core.projects.show', [$this->workspaceId, $this->projectId]));
    }

    public function test_workflow_activity_disappears_when_product_or_local_resource_access_is_lost(): void
    {
        $user = PlatformUser::query()->findOrFail($this->userId);
        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.workflows', $this->workspaceId))
            ->assertSeeText('Deployment v12 succeeded');

        DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->membershipId)
            ->where('product', 'monitor')
            ->update(['revoked_at' => now()]);

        $this->get(route('core.workspace.workflows', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('No connected workflow activity yet')
            ->assertDontSeeText('Deployment v12 succeeded');

        DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->membershipId)
            ->where('product', 'monitor')
            ->update(['revoked_at' => null]);
        $this->destinationStates->monitor = ProjectResourceDestinationState::AccessChanged;

        $this->get(route('core.workspace.workflows', $this->workspaceId))
            ->assertOk()
            ->assertDontSeeText('Deployment v12 succeeded');
    }
}
