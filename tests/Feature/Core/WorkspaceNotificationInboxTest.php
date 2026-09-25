<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\WorkspaceActivityProvider;
use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Data\Projects\WorkspaceActivitySnapshot;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceActivityProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceNotificationInboxTest extends TestCase
{
    private string $userId;

    private string $workspaceId;

    private string $membershipId;

    private string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        $this->userId = (string) Str::ulid();
        $this->workspaceId = (string) Str::ulid();
        $this->membershipId = (string) Str::ulid();
        $this->projectId = (string) Str::ulid();

        DB::connection('core')->table('users')->insert([
            'id' => $this->userId,
            'name' => 'Alex Owner',
            'email' => 'alex@example.test',
            'email_normalized' => 'alex@example.test',
            'password' => 'hashed-password',
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

        foreach (['monitor', 'deployer'] as $product) {
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
        DB::connection('core')->table('project_products')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->projectId,
            'product' => 'monitor',
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->registerProviders();
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        foreach ([
            'workspace_notification_preferences',
            'workspace_notification_reads',
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

    public function test_mark_all_read_only_updates_unread_items_matching_the_current_filters(): void
    {
        $user = PlatformUser::query()->findOrFail($this->userId);
        $response = $this->actingAs($user, 'platform')->get(route('core.workspace.notifications', [
            'workspace' => $this->workspaceId,
            'product' => 'monitor',
            'severity' => 'critical',
        ]));
        $response
            ->assertOk()
            ->assertSeeText('Monitor incident')
            ->assertDontSeeText('Deployer release')
            ->assertSeeText('Mark visible as read');

        $response = $this->post(route('core.workspace.notifications.read-all', [
            'workspace' => $this->workspaceId,
            'product' => 'monitor',
            'severity' => 'critical',
        ]));
        $response->assertRedirect(route('core.workspace.notifications', [
            'workspace' => $this->workspaceId,
            'product' => 'monitor',
            'severity' => 'critical',
        ]));

        $monitorKey = $this->notificationKey('monitor');
        $deployerKey = $this->notificationKey('deployer');
        $this->assertDatabaseHas('workspace_notification_reads', [
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'notification_key' => $monitorKey,
        ], 'core');
        $this->assertDatabaseMissing('workspace_notification_reads', [
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'notification_key' => $deployerKey,
        ], 'core');
    }

    public function test_revoked_workspace_membership_conceals_the_inbox(): void
    {
        $user = PlatformUser::query()->findOrFail($this->userId);
        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.notifications', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Monitor incident');

        DB::connection('core')->table('workspace_memberships')
            ->where('id', $this->membershipId)
            ->update(['revoked_at' => now()]);

        $this->get(route('core.workspace.notifications', $this->workspaceId))->assertNotFound();
    }

    public function test_project_preference_cannot_target_a_product_not_active_for_that_project(): void
    {
        $user = PlatformUser::query()->findOrFail($this->userId);

        $this->actingAs($user, 'platform')
            ->put(route('core.workspace.notifications.preferences.update', $this->workspaceId), [
                'project_id' => $this->projectId,
                'product' => 'deployer',
                'severity' => 'critical',
                'enabled' => '0',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('workspace_notification_preferences', 0, 'core');
    }

    private function createTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('password')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->json('preferences')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('status', 24)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->json('settings')->nullable();
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
            $table->timestamp('joined_at')->nullable();
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
        Schema::connection('core')->create('workspace_notification_reads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->char('notification_key', 64);
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id', 'notification_key']);
        });
        Schema::connection('core')->create('workspace_notification_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->char('project_id', 26)->nullable();
            $table->string('product', 24);
            $table->string('severity', 24);
            $table->char('scope_key', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id', 'scope_key']);
        });
    }

    private function registerProviders(): void
    {
        $registry = app(WorkspaceActivityProviderRegistry::class);
        foreach (['monitor', 'deployer'] as $product) {
            $registry->register($product, new class($product, $this->projectId) implements WorkspaceActivityProvider
            {
                public function __construct(private readonly string $product, private readonly string $projectId) {}

                public function recentForWorkspace(PlatformUser $user, Workspace $workspace, Collection $projects, int $limit): WorkspaceActivitySnapshot
                {
                    $recordedAt = CarbonImmutable::now('UTC');
                    $title = $this->product === 'monitor' ? 'Monitor incident' : 'Deployer release';
                    $run = new ProjectWorkflowRun(
                        key: $this->product.':notification-inbox-fixture',
                        title: $title,
                        recordedAt: $recordedAt,
                        projectId: $this->projectId,
                        steps: [new ProjectWorkflowStep(
                            product: $this->product,
                            productLabel: ucfirst($this->product),
                            title: $this->product === 'monitor' ? 'Incident check failed' : 'Release failed',
                            detail: 'Open the product for authorized details.',
                            state: ProjectWorkflowStepState::Failed,
                            recordedAt: $recordedAt,
                        )],
                    );

                    return new WorkspaceActivitySnapshot(collect([$run]));
                }
            });
        }
    }

    private function notificationKey(string $product): string
    {
        $runKey = $product.':notification-inbox-fixture';
        $stepTitle = $product === 'monitor' ? 'Incident check failed' : 'Release failed';

        return hash('sha256', $runKey.'|'.$product.'|'.$stepTitle);
    }
}
