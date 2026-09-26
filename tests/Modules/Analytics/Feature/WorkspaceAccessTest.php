<?php

namespace Tests\Modules\Analytics\Feature;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\MappedProjectResourceAccess;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Actions\Workspaces\EnsurePersonalWorkspace;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Invitation;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Notifications\WorkspaceInvitation;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Modules\Analytics\RefreshAnalyticsDatabase;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshAnalyticsDatabase;

    public function test_core_authority_gates_analytics_workspace_and_dashboard_destinations_on_product_grant(): void
    {
        config(['platform.products.analytics.auth_authority' => 'core']);

        $platformUser = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'name' => 'Core Analytics member',
            'email' => 'core-analytics@example.test',
            'email_normalized' => 'core-analytics@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
        ]);
        $canonicalWorkspace = CoreWorkspace::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'owner_user_id' => $platformUser->getKey(),
            'name' => 'Core Analytics workspace',
            'slug' => 'core-analytics-workspace',
            'status' => 'active',
        ]);
        $membershipId = (string) Str::ulid();
        DB::connection('core')->table('workspace_memberships')->insert([
            'id' => $membershipId,
            'workspace_id' => $canonicalWorkspace->getKey(),
            'user_id' => $platformUser->getKey(),
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $grantId = (string) Str::ulid();
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => $grantId,
            'membership_id' => $membershipId,
            'product' => 'analytics',
            'role' => 'owner',
            'status' => 'active',
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productUserId = DB::connection('analytics')->table('users')->insertGetId([
            'name' => 'Analytics member',
            'email' => 'analytics-member@example.test',
            'password' => 'hashed-password',
            'platform_user_id' => $platformUser->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $analyticsWorkspace = Workspace::create(['name' => 'Analytics workspace']);
        $analyticsWorkspace->users()->attach($productUserId, ['role' => WorkspaceRole::Owner->value]);
        $site = $analyticsWorkspace->sites()->create([
            'name' => 'Restricted Analytics site',
            'domains' => ['restricted.example.test'],
            'timezone' => 'UTC',
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'analytics',
            'source_entity' => 'workspace',
            'source_id' => (string) $analyticsWorkspace->getKey(),
            'canonical_entity' => 'workspace',
            'canonical_id' => $canonicalWorkspace->getKey(),
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'analytics',
            'source_entity' => 'user',
            'source_id' => (string) $productUserId,
            'canonical_entity' => 'user',
            'canonical_id' => $platformUser->getKey(),
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $principal = User::query()->findOrFail($productUserId);
        $access = new AnalyticsWorkspaceAccess(
            app(LegacyIdentityResolver::class),
            app(ProductAuthentication::class),
            app(ProductWorkspaceAccess::class),
            app(MappedProjectResourceAccess::class),
            app(ResolvePlatformUser::class),
            app(AnalyticsDeletionFence::class),
        );

        $this->assertTrue($access->hasAccess($principal, $analyticsWorkspace));
        $this->assertSame(WorkspaceRole::Owner, $access->roleFor($principal, $analyticsWorkspace));
        $this->assertCount(1, $access->workspacesFor($principal));

        DB::connection('core')->table('workspace_product_access')->where('id', $grantId)->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->assertFalse($access->hasAccess($principal, $analyticsWorkspace));
        $this->assertNull($access->roleFor($principal, $analyticsWorkspace));
        $this->assertCount(0, $access->workspacesFor($principal));

        try {
            app(EnsurePersonalWorkspace::class)->handle($principal, $site->getKey());
            $this->fail('A direct dashboard site destination must not survive revoked shared Analytics access.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_core_authority_does_not_auto_create_an_unlinked_analytics_workspace(): void
    {
        config(['platform.products.analytics.auth_authority' => 'core']);

        $platformUser = PlatformUser::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'name' => 'Core Analytics member',
            'email' => 'core-no-workspace@example.test',
            'email_normalized' => 'core-no-workspace@example.test',
            'password' => 'hashed-password',
            'status' => 'active',
        ]);
        $productUserId = DB::connection('analytics')->table('users')->insertGetId([
            'name' => 'Analytics member',
            'email' => 'analytics-no-workspace@example.test',
            'password' => 'hashed-password',
            'platform_user_id' => $platformUser->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('core')->table('legacy_identity_maps')->insert([
            'id' => (string) Str::ulid(),
            'source_product' => 'analytics',
            'source_entity' => 'user',
            'source_id' => (string) $productUserId,
            'canonical_entity' => 'user',
            'canonical_id' => $platformUser->getKey(),
            'status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnsurePersonalWorkspace::class)->handle($platformUser);
            $this->fail('Core authority must require a Core workspace grant.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(0, Workspace::query()->count());
    }

    public function test_owner_can_invite_and_recipient_can_join_workspace(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Client workspace']);
        $workspace->users()->attach($owner, ['role' => WorkspaceRole::Owner->value]);
        $csrf = 'test-token';

        $this->withSession(['_token' => $csrf, 'auth.password_confirmed_at' => now()->timestamp])->actingAs($owner)->post(route('analytics.workspaces.invitations.store', $workspace), [
            '_token' => $csrf,
            'email' => 'viewer@example.com',
            'role' => 'viewer',
        ])->assertRedirect();

        $invitation = Invitation::query()->sole();
        $this->assertSame('viewer', $invitation->role);
        Notification::assertSentOnDemand(WorkspaceInvitation::class);

        $recipient = User::factory()->create(['email' => 'viewer@example.com']);
        $token = 'token-for-test';
        $invitation->update(['token_hash' => hash('sha256', $token)]);

        $this->actingAs($recipient)->get(route('analytics.invitations.show', $token))->assertOk();
        $this->actingAs($recipient)->withSession(['_token' => 'test-token'])->post(route('analytics.invitations.accept', $token), ['_token' => 'test-token'])->assertRedirect(route('analytics.dashboard'));
        $this->assertSame(WorkspaceRole::Viewer, $workspace->fresh()->roleFor($recipient->getKey()));
    }

    public function test_member_cannot_access_another_workspace_site(): void
    {
        $member = User::factory()->create();
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Private workspace']);
        $workspace->users()->attach($owner, ['role' => WorkspaceRole::Owner->value]);
        $site = $workspace->sites()->create(['name' => 'Private site', 'domains' => ['private.example'], 'timezone' => 'UTC']);

        $this->actingAs($member)->get(route('analytics.sites.settings', $site))->assertForbidden();
    }

    public function test_viewer_can_read_goals_but_cannot_change_site_configuration(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Read-only workspace']);
        $workspace->users()->attach($owner, ['role' => WorkspaceRole::Owner->value]);
        $workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
        $site = $workspace->sites()->create(['name' => 'Read-only site', 'domains' => ['readonly.example'], 'timezone' => 'UTC']);

        $this->actingAs($viewer)->get(route('analytics.goals.index', $site))->assertOk();
        $this->actingAs($viewer)->get(route('analytics.sites.settings', $site))->assertForbidden();
        $this->actingAs($viewer)->get(route('analytics.sites.setup', $site))->assertForbidden();
        $this->actingAs($viewer)->post(route('analytics.goals.store', $site), [
            'name' => 'Blocked goal',
            'kind' => 'path',
            'match_type' => 'exact',
            'match_value' => '/blocked',
        ])->assertForbidden();
    }
}
