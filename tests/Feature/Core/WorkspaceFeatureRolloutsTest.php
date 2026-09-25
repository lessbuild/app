<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\WorkspaceCredentialProvider;
use App\Core\Data\Credentials\WorkspaceCredentialSnapshot;
use App\Core\Http\Middleware\EnsureWorkspaceFeatureRollout;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceFeatureRollout;
use App\Core\Models\WorkspaceFeatureRolloutChange;
use App\Core\Models\WorkspaceFeatureRolloutMetric;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\WorkspaceCredentialProviderRegistry;
use App\Core\Services\WorkspaceFeatureRollouts;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class WorkspaceFeatureRolloutsTest extends TestCase
{
    private PlatformUser $owner;

    private Workspace $workspace;

    private WorkspaceMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        $this->createTables();
        (require app_path('Core/Database/Migrations/2026_09_25_210000_create_workspace_feature_rollouts.php'))->up();
        foreach (WorkspaceFeatureRollouts::FEATURES as $feature) {
            config(['workspace-rollouts.features.'.$feature.'.available' => true,
                'workspace-rollouts.features.'.$feature.'.workspace_managed' => true,
                'workspace-rollouts.features.'.$feature.'.default_enabled' => true,
                'workspace-rollouts.features.'.$feature.'.workspace_ids' => null]);
        }

        $this->owner = PlatformUser::query()->create(['name' => 'Workspace Owner', 'email' => 'owner@example.test', 'status' => 'active']);
        $this->workspace = Workspace::query()->create([
            'owner_user_id' => $this->owner->getKey(), 'name' => 'Pilot workspace', 'slug' => 'pilot', 'status' => 'active',
        ]);
        $this->membership = WorkspaceMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'user_id' => $this->owner->getKey(), 'role' => 'owner', 'status' => 'active',
        ]);
        Auth::forgetGuards();
        $this->actingAs($this->owner, 'platform');
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        URL::forceRootUrl(null);
        (require app_path('Core/Database/Migrations/2026_09_25_210000_create_workspace_feature_rollouts.php'))->down();
        foreach (['project_products', 'project_memberships', 'projects', 'workspace_product_access', 'workspace_memberships', 'workspaces', 'users'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_existing_shared_views_stay_enabled_without_overrides_and_do_not_grant_product_access(): void
    {
        $provider = Mockery::mock(WorkspaceCredentialProvider::class);
        $provider->shouldNotReceive('credentialsForWorkspace');
        app(WorkspaceCredentialProviderRegistry::class)->register('deployer', $provider);

        $this->get(route('core.workspace.credentials', $this->workspace))->assertOk();
        $this->get(route('core.workspace.deliveries', $this->workspace))->assertOk();

        $this->assertSame(0, WorkspaceProductAccess::query()->count());
        $this->assertSame(0, WorkspaceFeatureRollout::query()->count());
        $this->assertSame(2, WorkspaceFeatureRolloutMetric::query()->count());
        $this->assertEquals(2, WorkspaceFeatureRolloutMetric::query()->sum('completed_count'));
    }

    public function test_owner_can_pause_one_view_and_restore_default_without_affecting_another_workspace_or_view(): void
    {
        $other = Workspace::query()->create([
            'owner_user_id' => $this->owner->getKey(), 'name' => 'Other workspace', 'slug' => 'other', 'status' => 'active',
        ]);
        $url = route('core.workspace.feature-rollouts.update', [$this->workspace, 'credential_inventory']);
        $this->patch($url, ['state' => 'disabled'])->assertRedirect(route('core.workspace.feature-rollouts.index', $this->workspace));
        $this->get(route('core.workspace.credentials', $this->workspace))->assertForbidden()->assertSeeText('This shared view is paused');
        $rollouts = app(WorkspaceFeatureRollouts::class);
        $this->assertTrue($rollouts->state($this->workspace, 'delivery_history')['enabled']);
        $this->assertTrue($rollouts->state($other, 'credential_inventory')['enabled']);
        $this->get(route('core.workspace.feature-rollouts.index', $other))->assertNotFound();
        $this->patch($url, ['state' => 'default'])->assertRedirect();
        $this->assertTrue($rollouts->state($this->workspace, 'credential_inventory')['enabled']);
        $this->assertSame(0, WorkspaceFeatureRollout::query()->count());
        $changes = WorkspaceFeatureRolloutChange::query()->orderBy('id')->get();
        $this->assertCount(2, $changes);
        $this->assertSame($this->owner->getKey(), $changes[0]->actor_user_id);
        $this->assertFalse($changes[0]->enabled);
        $this->assertNull($changes[1]->enabled);
    }

    public function test_only_current_owners_and_admins_can_manage_workspace_rollouts(): void
    {
        $index = route('core.workspace.feature-rollouts.index', $this->workspace);
        $update = route('core.workspace.feature-rollouts.update', [$this->workspace, 'delivery_history']);
        foreach (['member', 'billing'] as $role) {
            $this->membership->update(['role' => $role]);
            $this->get($index)->assertForbidden();
            $this->patch($update, ['state' => 'disabled'])->assertForbidden();
        }
        $this->membership->update(['role' => 'admin']);
        $this->get($index)->assertOk()->assertSeeText('Last 14 UTC days');
        $this->patch($update, ['state' => 'disabled'])->assertRedirect();
        $this->membership->update(['revoked_at' => now()]);
        $this->patch($update, ['state' => 'enabled'])->assertNotFound();
        $this->get(route('core.workspace.deliveries', $this->workspace))->assertNotFound();
        $this->assertSame(0, WorkspaceFeatureRolloutMetric::query()->count());
    }

    public function test_expiry_archiving_and_user_deactivation_are_rechecked_without_stale_permission_caches(): void
    {
        $url = route('core.workspace.feature-rollouts.index', $this->workspace);
        $this->get($url)->assertOk();
        $this->membership->update(['expires_at' => now()->subSecond()]);
        $this->get($url)->assertNotFound();
        $this->membership->update(['expires_at' => null]);
        $this->workspace->update(['archived_at' => now()]);
        $this->get($url)->assertNotFound();
        $this->workspace->update(['archived_at' => null]);
        PlatformUser::query()->whereKey($this->owner->getKey())->update(['status' => 'inactive']);
        $this->get($url)->assertForbidden();
    }

    public function test_server_availability_and_pilot_allowlist_override_stored_enabled_preferences(): void
    {
        $url = route('core.workspace.feature-rollouts.update', [$this->workspace, 'credential_inventory']);
        $this->patch($url, ['state' => 'enabled'])->assertRedirect();
        config(['workspace-rollouts.features.credential_inventory.available' => false]);
        $this->patchJson($url, ['state' => 'enabled'])->assertUnprocessable()->assertJsonValidationErrors('state');
        $this->get(route('core.workspace.credentials', $this->workspace))->assertForbidden();

        config(['workspace-rollouts.features.credential_inventory.available' => true,
            'workspace-rollouts.features.credential_inventory.workspace_ids' => []]);
        $this->get(route('core.workspace.credentials', $this->workspace))->assertForbidden();
        config(['workspace-rollouts.features.credential_inventory.workspace_ids' => [(string) $this->workspace->getKey()]]);
        $this->get(route('core.workspace.credentials', $this->workspace))->assertOk();
        $this->assertSame(1, WorkspaceFeatureRolloutChange::query()->count());
    }

    public function test_server_managed_settings_and_unknown_feature_keys_cannot_be_changed(): void
    {
        $url = route('core.workspace.feature-rollouts.update', [$this->workspace, 'delivery_history']);
        $this->patch($url, ['state' => 'disabled'])->assertRedirect();
        config(['workspace-rollouts.features.delivery_history.workspace_managed' => false]);
        $this->assertTrue(app(WorkspaceFeatureRollouts::class)->state($this->workspace, 'delivery_history')['enabled']);
        $this->patchJson($url, ['state' => 'default'])->assertUnprocessable();
        $this->patch(route('core.workspace.feature-rollouts.update', [$this->workspace, 'database.default']), ['state' => 'enabled'])->assertNotFound();
        $this->assertSame(1, WorkspaceFeatureRolloutChange::query()->count());
    }

    public function test_product_outages_are_recorded_as_partial_results_and_app_grants_remain_authoritative(): void
    {
        $grant = WorkspaceProductAccess::query()->create([
            'membership_id' => $this->membership->getKey(), 'product' => 'deployer', 'role' => 'owner', 'status' => 'active',
        ]);
        $provider = Mockery::mock(WorkspaceCredentialProvider::class);
        $provider->shouldReceive('credentialsForWorkspace')->once()->andReturn(new WorkspaceCredentialSnapshot(collect(), false));
        app(WorkspaceCredentialProviderRegistry::class)->register('deployer', $provider);
        $this->get(route('core.workspace.credentials', $this->workspace))->assertOk()->assertSeeText('Some credential sources are unavailable');
        $grant->update(['revoked_at' => now()]);
        $this->get(route('core.workspace.credentials', $this->workspace))->assertOk()->assertDontSeeText('Some credential sources are unavailable');
        $metric = WorkspaceFeatureRolloutMetric::query()->sole();
        $this->assertEquals(2, $metric->exposed_count);
        $this->assertEquals(1, $metric->degraded_count);
        $this->assertEquals(1, $metric->completed_count);
        $this->assertNotNull($metric->last_failure_at);
    }

    public function test_failure_and_validation_outcomes_are_aggregated_without_recording_sensitive_input(): void
    {
        $request = Request::create('/rollout?token=secret-query', 'GET', ['password' => 'secret-body']);
        $route = new Route('GET', '/rollout', fn () => null);
        $route->bind($request);
        $route->setParameter('workspace', $this->workspace);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $this->owner);
        $middleware = app(EnsureWorkspaceFeatureRollout::class);

        foreach ([new RuntimeException('secret-provider-response'), ValidationException::withMessages(['token' => 'secret-validation'])] as $exception) {
            try {
                $middleware->handle($request, function () use ($exception): never {
                    throw $exception;
                }, 'delivery_history');
                $this->fail('The source exception must be preserved.');
            } catch (RuntimeException|ValidationException $caught) {
                $this->assertSame($exception, $caught);
            }
        }
        $metric = WorkspaceFeatureRolloutMetric::query()->sole();
        $this->assertEquals(2, $metric->exposed_count);
        $this->assertEquals(1, $metric->failed_count);
        $this->assertEquals(1, $metric->rejected_count);
        $this->assertStringNotContainsString('secret-', $metric->toJson());
        $this->assertSame(0, WorkspaceFeatureRolloutChange::query()->count());
    }

    private function createTables(): void
    {
        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('owner_user_id');
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->ulid('user_id');
            $table->string('role');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('membership_id');
            $table->string('product');
            $table->string('role');
            $table->string('status');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('workspace_id');
            $table->string('name');
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->ulid('user_id');
            $table->string('status');
            $table->timestamp('revoked_at')->nullable();
        });
        Schema::connection('core')->create('project_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('project_id');
            $table->string('product');
            $table->string('status');
        });
    }
}
