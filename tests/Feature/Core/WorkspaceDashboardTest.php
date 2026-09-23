<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceDashboardTest extends TestCase
{
    private string $userId;

    private string $workspaceId;

    private string $membershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        $this->userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->membershipId = (string) Str::ulid();

        DB::connection('core')->table('users')->insert([
            'id' => $this->userId,
            'name' => 'Alex Rivera',
            'email' => 'alex@example.test',
            'email_normalized' => 'alex@example.test',
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

        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        foreach ([
            'current_product_subscriptions',
            'product_subscriptions',
            'project_connections',
            'project_lifecycle_events',
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

    public function test_workspace_overview_shows_only_accessible_projects_and_separate_app_subscriptions(): void
    {
        $visibleProjectId = (string) Str::ulid();
        $secondProjectId = (string) Str::ulid();
        $hiddenProjectId = (string) Str::ulid();

        foreach ([
            [$visibleProjectId, 'Checkout'],
            [$secondProjectId, 'Docs portal'],
            [$hiddenProjectId, 'Private project'],
        ] as [$projectId, $name]) {
            DB::connection('core')->table('projects')->insert([
                'id' => $projectId,
                'workspace_id' => $this->workspaceId,
                'created_by_user_id' => $this->userId,
                'name' => $name,
                'slug' => Str::slug($name),
                'status' => 'active',
                'description' => null,
                'metadata' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([$visibleProjectId, $secondProjectId] as $projectId) {
            DB::connection('core')->table('project_memberships')->insert([
                'id' => (string) Str::ulid(),
                'project_id' => $projectId,
                'user_id' => $this->userId,
                'role' => 'owner',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['deployer', 'monitor', 'analytics'] as $product) {
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

        foreach ([
            [$visibleProjectId, 'deployer', 'active'],
            [$visibleProjectId, 'monitor', 'active'],
            [$secondProjectId, 'analytics', 'active'],
            [$secondProjectId, 'monitor', 'provisioning'],
        ] as [$projectId, $product, $status]) {
            DB::connection('core')->table('project_products')->insert([
                'id' => (string) Str::ulid(),
                'project_id' => $projectId,
                'product' => $product,
                'status' => $status,
                'metadata' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([
            ['deployer', 'scale', 'active'],
            ['monitor', 'team', 'trialing'],
            ['analytics', 'growth', 'active'],
        ] as [$product, $plan, $status]) {
            $productSubscriptionId = (string) Str::ulid();
            DB::connection('core')->table('product_subscriptions')->insert([
                'id' => $productSubscriptionId,
                'workspace_id' => $this->workspaceId,
                'product' => $product,
                'provider' => 'stripe',
                'provider_account_key' => $product,
                'provider_subscription_id' => 'sub_'.$product.'_123',
                'plan_key' => $plan,
                'status' => $status,
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('core')->table('current_product_subscriptions')->insert([
                'id' => (string) Str::ulid(),
                'workspace_id' => $this->workspaceId,
                'product' => $product,
                'product_subscription_id' => $productSubscriptionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $deployerResourceId = (string) Str::ulid();
        $monitorResourceId = (string) Str::ulid();
        foreach ([
            [$deployerResourceId, $visibleProjectId, 'deployer', 'Production'],
            [$monitorResourceId, $visibleProjectId, 'monitor', 'Production checks'],
        ] as [$resourceId, $projectId, $product, $name]) {
            DB::connection('core')->table('project_resources')->insert([
                'id' => $resourceId,
                'project_id' => $projectId,
                'product' => $product,
                'resource_type' => 'environment',
                'resource_id' => (string) random_int(100, 999),
                'name' => $name,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::connection('core')->table('project_connections')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $visibleProjectId,
            'source_resource_id' => $deployerResourceId,
            'target_resource_id' => $monitorResourceId,
            'capabilities' => json_encode(['deployment_context']),
            'status' => 'active',
            'last_succeeded_at' => now()->subMinutes(8),
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs(PlatformUser::query()->findOrFail($this->userId), 'platform')
            ->get(route('core.workspace.dashboard', $this->workspaceId));

        $response
            ->assertOk()
            ->assertSeeText('Overview')
            ->assertSeeText('Workspace overview')
            ->assertSeeText('Northstar Studio')
            ->assertSeeText('Checkout')
            ->assertSeeText('Scale')
            ->assertSeeText('Deployer · Connected')
            ->assertSeeText('Monitor · Connected')
            ->assertSeeText('Monitor · Setting up')
            ->assertSeeText('Docs portal')
            ->assertSeeText('Growth')
            ->assertSeeText('Production → Production checks')
            ->assertDontSeeText('Private project')
            ->assertSeeText('Each app keeps its own plan and usage limits.');

        DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->membershipId)
            ->where('product', 'monitor')
            ->delete();

        $this->get(route('core.workspace.dashboard', $this->workspaceId))
            ->assertOk()
            ->assertDontSeeText('Production checks')
            ->assertSeeText('0 workflows');
    }

    public function test_single_workspace_landing_redirects_to_the_overview(): void
    {
        $this->actingAs(PlatformUser::query()->findOrFail($this->userId), 'platform')
            ->get(route('core.home'))
            ->assertRedirect(route('core.workspace.dashboard', $this->workspaceId));
    }

    public function test_project_can_be_edited_archived_and_restored_without_removing_product_history(): void
    {
        $projectId = (string) Str::ulid();
        $deployerResourceId = (string) Str::ulid();
        $monitorResourceId = (string) Str::ulid();
        $subscriptionId = (string) Str::ulid();

        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->userId,
            'name' => 'Legacy storefront',
            'slug' => 'legacy-storefront',
            'status' => 'active',
            'description' => 'Original description',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'user_id' => $this->userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([['deployer', $deployerResourceId], ['monitor', $monitorResourceId]] as [$product, $resourceId]) {
            DB::connection('core')->table('project_resources')->insert([
                'id' => $resourceId,
                'project_id' => $projectId,
                'product' => $product,
                'resource_type' => 'application',
                'resource_id' => 'source-'.$product,
                'name' => str($product)->headline().' production',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::connection('core')->table('project_products')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'product' => 'deployer',
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => (string) Str::ulid(),
            'membership_id' => $this->membershipId,
            'product' => 'deployer',
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_connections')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'source_resource_id' => $deployerResourceId,
            'target_resource_id' => $monitorResourceId,
            'capabilities' => json_encode(['deployment_context']),
            'status' => 'disconnected',
            'disconnected_at' => now()->subHour(),
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('product_subscriptions')->insert([
            'id' => $subscriptionId,
            'workspace_id' => $this->workspaceId,
            'product' => 'deployer',
            'provider' => 'stripe',
            'provider_subscription_id' => 'sub_preserved_123',
            'plan_key' => 'scale',
            'status' => 'active',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = PlatformUser::query()->findOrFail($this->userId);
        $this->actingAs($user, 'platform')
            ->get(route('core.projects.edit', [$this->workspaceId, $projectId]))
            ->assertOk()
            ->assertSeeText('Edit project')
            ->assertSeeText('Archive this project');

        $this->put(route('core.projects.update', [$this->workspaceId, $projectId]), [
            'name' => 'Storefront',
            'description' => 'Shared project for production storefront services.',
        ])->assertRedirect(route('core.projects.show', [$this->workspaceId, $projectId]));

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'name' => 'Storefront',
            'description' => 'Shared project for production storefront services.',
            'status' => 'active',
        ], 'core');
        $updatedEvent = DB::connection('core')->table('project_lifecycle_events')
            ->where('project_id', $projectId)
            ->where('event_type', 'updated')
            ->first();
        $this->assertSame($this->userId, $updatedEvent->actor_user_id);
        $this->assertSame(['changed_fields' => ['name', 'description']], json_decode($updatedEvent->details, true));

        $this->post(route('core.projects.archive', [$this->workspaceId, $projectId]))
            ->assertRedirect(route('core.projects.index', ['workspace' => $this->workspaceId, 'status' => 'archived']));

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'status' => 'archived',
        ], 'core');
        $this->assertNotNull(DB::connection('core')->table('projects')->where('id', $projectId)->value('archived_at'));
        $this->assertSame(2, DB::connection('core')->table('project_resources')->where('project_id', $projectId)->count());
        $this->assertSame(1, DB::connection('core')->table('project_products')->where('project_id', $projectId)->count());
        $this->assertSame(1, DB::connection('core')->table('project_connections')->where('project_id', $projectId)->count());
        $this->assertSame(1, DB::connection('core')->table('product_subscriptions')->where('id', $subscriptionId)->count());
        $this->assertSame(2, DB::connection('core')->table('project_lifecycle_events')->where('project_id', $projectId)->count());
        $this->assertSame(2, DB::connection('core')->table('project_lifecycle_events')->where('project_id', $projectId)->where('actor_user_id', $this->userId)->count());
        $this->get(route('core.projects.show', [$this->workspaceId, $projectId]))->assertNotFound();

        $this->get(route('core.projects.index', ['workspace' => $this->workspaceId, 'status' => 'archived']))
            ->assertOk()
            ->assertSeeText('Storefront')
            ->assertSeeText('Restore project')
            ->assertSeeText('Linked');

        $this->post(route('core.projects.restore', [$this->workspaceId, $projectId]))
            ->assertRedirect(route('core.projects.show', [$this->workspaceId, $projectId]));

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'status' => 'active',
            'archived_at' => null,
        ], 'core');
        $this->assertSame(1, DB::connection('core')->table('project_connections')->where('project_id', $projectId)->count());
        $this->assertSame(3, DB::connection('core')->table('project_lifecycle_events')->where('project_id', $projectId)->count());
        $this->assertSame(1, DB::connection('core')->table('project_lifecycle_events')
            ->where('project_id', $projectId)
            ->where('event_type', 'restored')
            ->where('actor_user_id', $this->userId)
            ->count());

        $this->get(route('core.projects.index', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Storefront')
            ->assertSeeText('Connected');
    }

    public function test_workspace_members_cannot_edit_or_archive_a_project(): void
    {
        $memberId = (string) Str::ulid();
        $projectId = (string) Str::ulid();
        DB::connection('core')->table('users')->insert([
            'id' => $memberId,
            'name' => 'Jamie Member',
            'email' => 'jamie@example.test',
            'email_normalized' => 'jamie@example.test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $this->workspaceId,
            'user_id' => $memberId,
            'role' => 'member',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->userId,
            'name' => 'Shared project',
            'slug' => 'shared-project',
            'status' => 'active',
            'description' => null,
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'user_id' => $memberId,
            'role' => 'member',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $member = PlatformUser::query()->findOrFail($memberId);
        $this->actingAs($member, 'platform')
            ->get(route('core.projects.edit', [$this->workspaceId, $projectId]))
            ->assertForbidden();

        $this->put(route('core.projects.update', [$this->workspaceId, $projectId]), [
            'name' => 'Unauthorized edit',
            'description' => null,
        ])->assertForbidden();

        $this->post(route('core.projects.archive', [$this->workspaceId, $projectId]))
            ->assertForbidden();

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'status' => 'active',
            'archived_at' => null,
        ], 'core');
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
        Schema::connection('core')->create('project_lifecycle_events', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('actor_user_id', 26)->nullable();
            $table->string('event_type', 40);
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');
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
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('automation_paused_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('product_subscriptions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('product', 24);
            $table->string('provider', 24);
            $table->string('provider_account_key', 100)->nullable();
            $table->string('provider_subscription_id', 191)->nullable();
            $table->string('plan_key', 100)->nullable();
            $table->string('status', 24);
            $table->unsignedInteger('quantity')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
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
}
