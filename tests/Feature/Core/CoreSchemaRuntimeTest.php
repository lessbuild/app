<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceDashboardSelection;
use App\Core\Models\WorkspaceDashboardView;
use App\Core\Models\WorkspaceProjectPin;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CoreSchemaRuntimeTest extends TestCase
{
    public function test_fresh_core_migrations_support_membership_expiry_and_workspace_dashboard_access(): void
    {
        Artisan::call('platform:migrate', ['module' => 'core']);

        $this->assertSame('core', config('session.connection'));
        $this->assertTrue(Schema::connection('core')->hasTable('sessions'));
        $this->assertTrue(Schema::connection('core')->hasColumn('workspace_memberships', 'expires_at'));
        $this->assertTrue(Schema::connection('core')->hasColumn('workspace_memberships', 'revoked_at'));

        $userId = (string) Str::ulid();
        $workspaceId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();
        DB::connection('core')->table('users')->insert([
            'id' => $userId,
            'name' => 'Migration smoke test',
            'email' => 'migration-smoke@example.test',
            'email_normalized' => 'migration-smoke@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspaces')->insert([
            'id' => $workspaceId,
            'owner_user_id' => $userId,
            'name' => 'Migration smoke workspace',
            'slug' => 'migration-smoke-workspace',
            'status' => 'active',
            'settings' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'role' => 'owner',
            'status' => 'active',
            'expires_at' => now()->subMinute(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = PlatformUser::query()->findOrFail($userId);
        $workspace = Workspace::query()->findOrFail($workspaceId);
        $access = app(WorkspaceProjectAccess::class);

        $this->assertNull($access->activeMembership($user, $workspace));
        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.dashboard', $workspace))
            ->assertNotFound();

        DB::connection('core')->table('workspace_memberships')
            ->where('id', $membershipId)
            ->update(['expires_at' => now()->addHour()]);

        $this->assertNotNull($access->activeMembership($user, $workspace));

        DB::connection('core')->table('workspace_memberships')
            ->where('id', $membershipId)
            ->update(['revoked_at' => now()]);

        $this->assertNull($access->activeMembership($user, $workspace));
        $this->get(route('core.workspace.dashboard', $workspace))
            ->assertNotFound();

        DB::connection('core')->table('workspace_memberships')
            ->where('id', $membershipId)
            ->update(['revoked_at' => null]);

        $this->actingAs($user, 'platform')
            ->get(route('core.workspace.dashboard', $workspace))
            ->assertOk()
            ->assertSeeText('Migration smoke workspace');
        $this->assertTrue(Schema::connection('core')->hasTable('workspace_dashboard_views'));
        $this->assertTrue(Schema::connection('core')->hasTable('workspace_dashboard_selections'));
        $this->assertTrue(Schema::connection('core')->hasTable('workspace_project_pins'));
        $this->assertTrue(Schema::connection('core')->hasTable('platform_auth_sessions'));
        $this->assertTrue(Schema::connection('core')->hasTable('platform_sso_tickets'));
        $this->assertTrue(Schema::connection('core')->hasTable('platform_registration_mutexes'));

        $projectId = (string) Str::ulid();
        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $userId,
            'name' => 'Pinned migration project',
            'slug' => 'pinned-migration-project',
            'status' => 'active',
            'description' => null,
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $view = WorkspaceDashboardView::query()->create([
            'workspace_id' => $workspaceId,
            'visibility' => 'workspace',
            'scope_key' => 'workspace',
            'owner_user_id' => null,
            'created_by_user_id' => $userId,
            'name' => 'Migration saved view',
            'filters' => ['product' => 'all', 'pinned_only' => true],
        ]);
        WorkspaceProjectPin::query()->create([
            'workspace_id' => $workspaceId,
            'project_id' => $projectId,
            'visibility' => 'workspace',
            'scope_key' => 'workspace',
            'owner_user_id' => null,
            'created_by_user_id' => $userId,
        ]);
        WorkspaceDashboardSelection::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'view_id' => $view->getKey(),
        ]);

        $this->assertSame(['product' => 'all', 'pinned_only' => true], $view->fresh()->filters);
        $view->delete();
        $this->assertDatabaseHas('workspace_dashboard_selections', [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'view_id' => null,
        ], 'core');

    }
}
