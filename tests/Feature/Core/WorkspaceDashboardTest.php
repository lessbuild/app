<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\ProjectProductSummaryProvider;
use App\Core\Contracts\ProjectSetupProvider;
use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Services\ProjectProductSummaryRegistry;
use App\Core\Services\ProjectSetupRegistry;
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
            'workspace_project_pins',
            'workspace_dashboard_selections',
            'workspace_dashboard_views',
            'current_product_subscriptions',
            'product_subscriptions',
            'project_connections',
            'project_lifecycle_events',
            'project_resources',
            'project_environments',
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
        $summaryCalls = (object) ['products' => []];
        $summaryRegistry = app(ProjectProductSummaryRegistry::class);
        foreach (['deployer', 'monitor', 'analytics'] as $product) {
            $summaryRegistry->register($product, new class($product, $summaryCalls) implements ProjectProductSummaryProvider
            {
                public function __construct(private readonly string $product, private readonly object $calls) {}

                public function summarize(PlatformUser $user, Project $project): ?ProjectProductSnapshot
                {
                    $this->calls->products[] = $this->product.':'.$project->name;
                    $needsAttention = $this->product === 'monitor' && $project->name === 'Checkout';

                    return new ProjectProductSnapshot(
                        title: str($this->product)->headline().' activity',
                        detail: $needsAttention ? '2 open incidents · 1 check down' : 'Fresh summary for '.$project->name,
                        state: $needsAttention ? ProjectProductSnapshotState::Attention : ProjectProductSnapshotState::Current,
                    );
                }
            });
        }

        $setupRegistry = app(ProjectSetupRegistry::class);
        foreach (['deployer', 'monitor', 'analytics'] as $product) {
            $setupRegistry->register($product, new class($product) implements ProjectSetupProvider
            {
                public function __construct(private readonly string $product) {}

                public function steps(PlatformUser $user, Project $project): array
                {
                    $needsAction = $this->product === 'deployer' && $project->name === 'Checkout';

                    return [new ProjectSetupStep(
                        id: $this->product.'.dashboard-test',
                        product: $this->product,
                        title: 'Finish '.$this->product.' setup',
                        detail: 'Connect and verify '.$this->product.' for '.$project->name.'.',
                        state: $needsAction ? ProjectSetupStepState::NeedsAction : ProjectSetupStepState::Complete,
                    )];
                }
            });
        }

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
        DB::connection('core')->table('project_connections')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $visibleProjectId,
            'source_resource_id' => $deployerResourceId,
            'target_resource_id' => $monitorResourceId,
            'capabilities' => json_encode(['deployment_context']),
            'status' => 'failed',
            'last_error_code' => 'product_access_changed',
            'last_error_at' => now()->subMinutes(5),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subMinutes(5),
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
            ->assertSeeText('Deployer activity')
            ->assertSeeText('Monitor activity')
            ->assertSeeText('Analytics activity')
            ->assertSeeText('Fresh summary for Checkout')
            ->assertDontSeeText('Fresh summary for Private project')
            ->assertSeeText('Workspace priorities')
            ->assertSeeText('Repair an app connection')
            ->assertSeeText('Access changed')
            ->assertDontSeeText('product_access_changed')
            ->assertSeeText('2 open incidents')
            ->assertSeeText('Finish deployer setup')
            ->assertSeeText('Production → Production checks')
            ->assertDontSeeText('Private project')
            ->assertSeeText('Each app keeps its own plan and usage limits.');

        $this->assertEqualsCanonicalizing([
            'deployer:Checkout',
            'monitor:Checkout',
            'analytics:Docs portal',
        ], $summaryCalls->products);

        DB::connection('core')->table('project_resources')
            ->where('id', $monitorResourceId)
            ->update(['status' => 'stale']);

        $this->get(route('core.workspace.dashboard', $this->workspaceId))
            ->assertOk()
            ->assertDontSeeText('Production checks')
            ->assertDontSeeText('Access changed');

        DB::connection('core')->table('project_resources')
            ->where('id', $monitorResourceId)
            ->update(['status' => 'active']);

        DB::connection('core')->table('workspace_product_access')
            ->where('membership_id', $this->membershipId)
            ->where('product', 'monitor')
            ->delete();

        $this->get(route('core.workspace.dashboard', $this->workspaceId))
            ->assertOk()
            ->assertDontSeeText('Production checks')
            ->assertDontSeeText('Monitor activity')
            ->assertDontSeeText('2 open incidents')
            ->assertDontSeeText('Access changed')
            ->assertSeeText('0 workflows');
    }

    public function test_project_view_preserves_its_selected_environment_in_signal_navigation(): void
    {
        $projectId = $this->createVisibleProject('Checkout');
        $environmentId = (string) Str::ulid();
        DB::connection('core')->table('project_environments')->insert([
            'id' => $environmentId,
            'project_id' => $projectId,
            'created_by_user_id' => $this->userId,
            'name' => 'Staging',
            'slug' => 'staging',
            'environment_type' => 'staging',
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $selectedUrl = route('core.projects.show', [
            $this->workspaceId,
            $projectId,
            'context_environment' => $environmentId,
        ]);
        $this->actingAs(PlatformUser::query()->findOrFail($this->userId), 'platform')
            ->get($selectedUrl)
            ->assertOk()
            ->assertSeeText('Environment')
            ->assertSeeText('Staging')
            ->assertSee($selectedUrl.'#environments')
            ->assertSee(route('core.projects.show', [
                $this->workspaceId,
                $projectId,
                'context_environment' => $environmentId,
            ]).'#environment-'.$environmentId);
    }

    public function test_project_view_marks_a_missing_environment_unavailable_without_fallback(): void
    {
        $projectId = $this->createVisibleProject('Checkout');
        $environmentId = (string) Str::ulid();

        $this->actingAs(PlatformUser::query()->findOrFail($this->userId), 'platform')
            ->get(route('core.projects.show', [
                $this->workspaceId,
                $projectId,
                'context_environment' => $environmentId,
            ]))
            ->assertOk()
            ->assertSeeText('The selected shared environment is missing, inactive, or belongs to another project')
            ->assertSee('>Unavailable</span>', false)
            ->assertSee('context_environment='.$environmentId.'#environments');
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

    public function test_personal_saved_views_and_project_pins_persist_and_filter_only_currently_authorized_projects(): void
    {
        $projectId = (string) Str::ulid();
        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->userId,
            'name' => 'Pinned storefront',
            'slug' => 'pinned-storefront',
            'status' => 'active',
            'description' => null,
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

        $this->actingAs(PlatformUser::query()->findOrFail($this->userId), 'platform')
            ->post(route('core.workspace.views.store', $this->workspaceId), [
                'name' => 'My pinned projects',
                'visibility' => 'personal',
                'product' => 'all',
                'pinned_only' => '1',
            ])
            ->assertRedirect();

        $view = DB::connection('core')->table('workspace_dashboard_views')->first();
        $this->assertNotNull($view);
        $this->assertSame($this->userId, $view->owner_user_id);
        $this->assertSame('user:'.$this->userId, $view->scope_key);

        $this->put(route('core.workspace.views.update', [$this->workspaceId, $view->id]), [
            'name' => 'My pinned projects',
            'visibility' => 'personal',
            'product' => 'all',
            'pinned_only' => '1',
        ])->assertRedirect();
        $this->post(route('core.workspace.views.store', $this->workspaceId), [
            'name' => 'My pinned projects',
            'visibility' => 'personal',
            'product' => 'all',
            'pinned_only' => '1',
        ])->assertSessionHasErrors('name');

        $pinRoute = route('core.workspace.project-pins.update', [$this->workspaceId, $projectId, 'personal']);
        $this->put($pinRoute)->assertRedirect();
        $this->put($pinRoute)->assertRedirect();
        $this->assertDatabaseCount('workspace_project_pins', 1, 'core');

        $this->get(route('core.workspace.dashboard', ['workspace' => $this->workspaceId, 'view' => $view->id]))
            ->assertOk()
            ->assertSeeText('My pinned projects')
            ->assertSeeText('Pinned storefront')
            ->assertSeeText('Unpin for me');
        $this->assertDatabaseHas('workspace_dashboard_selections', [
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'view_id' => $view->id,
        ], 'core');

        // A fresh workspace landing, such as returning from another app host, restores the saved view.
        $this->get(route('core.workspace.dashboard', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('My pinned projects')
            ->assertSeeText('Pinned storefront');

        $this->delete(route('core.workspace.project-pins.destroy', [$this->workspaceId, $projectId, 'personal']))
            ->assertRedirect();
        $this->get(route('core.workspace.dashboard', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('My pinned projects')
            ->assertSeeText('No projects match this view')
            ->assertDontSeeText('Unpin for me');
        $this->get(route('core.workspace.dashboard', ['workspace' => $this->workspaceId, 'view' => 'all']))
            ->assertOk()
            ->assertSeeText('Recently updated');
        $this->assertDatabaseHas('workspace_dashboard_selections', [
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'view_id' => null,
        ], 'core');
        $this->get(route('core.workspace.dashboard', ['workspace' => $this->workspaceId, 'view' => $view->id]))
            ->assertOk()
            ->assertSeeText('My pinned projects');
        $this->delete(route('core.workspace.views.destroy', [$this->workspaceId, $view->id]))
            ->assertRedirect();
        $this->assertDatabaseMissing('workspace_dashboard_views', ['id' => $view->id], 'core');
        $this->get(route('core.workspace.dashboard', $this->workspaceId))
            ->assertOk()
            ->assertSeeText('Recently updated');
        $this->assertDatabaseHas('workspace_dashboard_selections', [
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'view_id' => null,
        ], 'core');
    }

    public function test_saved_view_filters_project_names_with_literal_contains_semantics(): void
    {
        $this->createVisibleProject('Invoice 100% release');
        $this->createVisibleProject('Invoice 100x release');

        $this->actingAs(PlatformUser::query()->findOrFail($this->userId), 'platform')
            ->post(route('core.workspace.views.store', $this->workspaceId), [
                'name' => 'Literal percent invoices',
                'visibility' => 'personal',
                'product' => 'all',
                'pinned_only' => '0',
                'project_name' => ' 100% ',
            ])
            ->assertRedirect();

        $view = DB::connection('core')->table('workspace_dashboard_views')->first();
        $this->assertNotNull($view);
        $this->assertSame('100%', json_decode($view->filters, true, flags: JSON_THROW_ON_ERROR)['project_name']);

        $response = $this->get(route('core.workspace.dashboard', ['workspace' => $this->workspaceId, 'view' => $view->id]));
        $response
            ->assertOk()
            ->assertSeeText('Invoice 100% release')
            ->assertSeeText('name contains “100%”');
        $this->assertSame(1, substr_count($response->getContent(), 'aria-label="Project pin options"'));

        $invalidViewId = (string) Str::ulid();
        DB::connection('core')->table('workspace_dashboard_views')->insert([
            'id' => $invalidViewId,
            'workspace_id' => $this->workspaceId,
            'visibility' => 'personal',
            'scope_key' => 'user:'.$this->userId,
            'owner_user_id' => $this->userId,
            'created_by_user_id' => $this->userId,
            'name' => 'Invalid filter',
            'filters' => json_encode(['product' => 'all', 'pinned_only' => false, 'project_name' => ['not-a-string']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invalidResponse = $this->get(route('core.workspace.dashboard', ['workspace' => $this->workspaceId, 'view' => $invalidViewId]))
            ->assertOk()
            ->assertSeeText('This saved view has an unavailable or invalid filter');
        $this->assertSame(0, substr_count($invalidResponse->getContent(), 'aria-label="Project pin options"'));
    }

    public function test_shared_pinned_only_views_use_workspace_pins_not_personal_pins(): void
    {
        $workspacePinnedId = (string) Str::ulid();
        $personalPinnedId = (string) Str::ulid();

        foreach ([[$workspacePinnedId, 'Workspace pinned project'], [$personalPinnedId, 'Personal pinned project']] as [$projectId, $name]) {
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

        $this->actingAs(PlatformUser::query()->findOrFail($this->userId), 'platform')
            ->put(route('core.workspace.project-pins.update', [$this->workspaceId, $workspacePinnedId, 'workspace']))
            ->assertRedirect();
        $this->put(route('core.workspace.project-pins.update', [$this->workspaceId, $personalPinnedId, 'personal']))
            ->assertRedirect();
        $this->post(route('core.workspace.views.store', $this->workspaceId), [
            'name' => 'Shared pins only',
            'visibility' => 'workspace',
            'product' => 'all',
            'pinned_only' => '1',
        ])->assertRedirect();

        $view = DB::connection('core')->table('workspace_dashboard_views')->first();
        $this->get(route('core.workspace.dashboard', ['workspace' => $this->workspaceId, 'view' => $view->id]))
            ->assertOk()
            ->assertSeeText('Workspace pinned project')
            ->assertSeeText('Unpin for workspace')
            ->assertDontSeeText('Unpin for me');
    }

    public function test_workspace_views_are_shared_but_each_request_rechecks_product_and_project_access(): void
    {
        $projectId = (string) Str::ulid();
        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->userId,
            'name' => 'Shared view project',
            'slug' => 'shared-view-project',
            'status' => 'active',
            'description' => null,
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

        $owner = PlatformUser::query()->findOrFail($this->userId);
        $this->actingAs($owner, 'platform')
            ->post(route('core.workspace.views.store', $this->workspaceId), [
                'name' => 'Shared Monitor view',
                'visibility' => 'workspace',
                'product' => 'monitor',
                'pinned_only' => '0',
            ])
            ->assertRedirect();
        $this->post(route('core.workspace.views.store', $this->workspaceId), [
            'name' => 'Shared all projects',
            'visibility' => 'workspace',
            'product' => 'all',
            'pinned_only' => '0',
        ])->assertRedirect();

        $view = DB::connection('core')->table('workspace_dashboard_views')->where('name', 'Shared Monitor view')->first();
        $allProjectsView = DB::connection('core')->table('workspace_dashboard_views')->where('name', 'Shared all projects')->first();
        $this->assertSame('workspace', $view->visibility);
        $this->assertNull($view->owner_user_id);
        $this->assertSame('workspace', $view->scope_key);

        $memberId = (string) Str::ulid();
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

        $member = PlatformUser::query()->findOrFail($memberId);
        $this->actingAs($member, 'platform')
            ->get(route('core.workspace.dashboard', ['workspace' => $this->workspaceId, 'view' => $view->id]))
            ->assertOk()
            ->assertSeeText('Shared Monitor view')
            ->assertSeeText('This saved view has an unavailable or invalid filter')
            ->assertDontSeeText('Shared view project');

        $personalViewId = (string) Str::ulid();
        DB::connection('core')->table('workspace_dashboard_views')->insert([
            'id' => $personalViewId,
            'workspace_id' => $this->workspaceId,
            'visibility' => 'personal',
            'scope_key' => 'user:'.$this->userId,
            'owner_user_id' => $this->userId,
            'created_by_user_id' => $this->userId,
            'name' => 'Owner private view',
            'filters' => json_encode(['product' => 'all', 'pinned_only' => false]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->get(route('core.workspace.dashboard', ['workspace' => $this->workspaceId, 'view' => $personalViewId]))
            ->assertNotFound();
        $this->put(route('core.workspace.views.update', [$this->workspaceId, $view->id]), [
            'name' => 'Changed shared view',
            'visibility' => 'workspace',
            'product' => 'all',
            'pinned_only' => '0',
        ])->assertForbidden();
        $this->delete(route('core.workspace.views.destroy', [$this->workspaceId, $view->id]))
            ->assertForbidden();

        $this->get(route('core.workspace.dashboard', ['workspace' => $this->workspaceId, 'view' => $allProjectsView->id]))
            ->assertOk()
            ->assertSeeText('Shared all projects')
            ->assertDontSeeText('Open project');

        DB::connection('core')->table('project_memberships')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'user_id' => $memberId,
            'role' => 'member',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->get(route('core.workspace.dashboard', ['workspace' => $this->workspaceId, 'view' => $allProjectsView->id]))
            ->assertOk()
            ->assertSeeText('Shared view project');
        $this->put(route('core.workspace.project-pins.update', [$this->workspaceId, $projectId, 'workspace']))
            ->assertForbidden();

        DB::connection('core')->table('project_memberships')->where('project_id', $projectId)->where('user_id', $memberId)->delete();
        $this->get(route('core.workspace.dashboard', ['workspace' => $this->workspaceId, 'view' => $allProjectsView->id]))
            ->assertOk()
            ->assertDontSeeText('Open project');
    }

    private function createTables(): void
    {
        Schema::connection('core')->create('workspace_dashboard_views', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('visibility', 16);
            $table->string('scope_key', 64);
            $table->char('owner_user_id', 26)->nullable();
            $table->char('created_by_user_id', 26)->nullable();
            $table->string('name', 80);
            $table->json('filters');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_dashboard_selections', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->char('view_id', 26)->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_project_pins', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('project_id', 26);
            $table->string('visibility', 16);
            $table->string('scope_key', 64);
            $table->char('owner_user_id', 26)->nullable();
            $table->char('created_by_user_id', 26)->nullable();
            $table->timestamps();
        });
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
        Schema::connection('core')->create('project_environments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('created_by_user_id', 26)->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('environment_type');
            $table->string('status', 24);
            $table->json('metadata')->nullable();
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
            $table->string('last_error_code', 120)->nullable();
            $table->timestamp('last_error_at')->nullable();
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

    private function createVisibleProject(string $name): string
    {
        $projectId = (string) Str::ulid();
        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $this->workspaceId,
            'created_by_user_id' => $this->userId,
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => 'active',
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

        return $projectId;
    }
}
