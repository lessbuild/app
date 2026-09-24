<?php

namespace Tests\Feature\Core;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceInvitation;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceMembershipEvent;
use App\Core\Notifications\PlatformVerifyEmail;
use App\Core\Notifications\WorkspaceInvitationNotification;
use App\Core\Services\Workspaces\CreateWorkspaceInvitation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceInvitationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'lessbuild.registration.enabled' => false,
            'lessbuild.registration.allow_first_user' => false,
            'lessbuild.registration.invitation_days' => 5,
        ]);

        Schema::connection('core')->create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->string('auth_type')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->json('preferences')->nullable();
            $table->string('status', 24)->default('active');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspaces', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('owner_user_id', 26);
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->string('status', 24)->default('active');
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 32);
            $table->string('status', 24);
            $table->char('invited_by_user_id', 26)->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });
        Schema::connection('core')->create('workspace_invitations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('invited_by_user_id', 26)->nullable();
            $table->string('email');
            $table->string('email_normalized')->index();
            $table->string('role', 32);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 24)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'email_normalized', 'status']);
        });
        Schema::connection('core')->create('workspace_product_access', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('membership_id', 26);
            $table->string('product', 24);
            $table->string('role', 32)->default('member');
            $table->string('status', 24)->default('active');
            $table->char('granted_by_user_id', 26)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['membership_id', 'product']);
        });
        Schema::connection('core')->create('projects', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->timestamps();
        });
        Schema::connection('core')->create('project_memberships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('project_id', 26);
            $table->char('user_id', 26);
            $table->string('role', 32)->default('member');
            $table->string('status', 24)->default('active');
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('workspace_membership_events', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->char('membership_id', 26)->nullable();
            $table->char('actor_user_id', 26)->nullable();
            $table->char('subject_user_id', 26)->nullable();
            $table->string('event', 48);
            $table->string('previous_role', 32)->nullable();
            $table->string('new_role', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'created_at']);
        });
        Schema::connection('core')->create('product_subscriptions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('product', 24);
            $table->string('provider', 40);
            $table->string('status', 32);
            $table->timestamps();
        });
        Schema::connection('core')->create('platform_registration_mutexes', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::connection('core')->table('platform_registration_mutexes')->insert(['id' => 1]);
        Schema::connection('core')->create('platform_auth_sessions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->index();
            $table->string('remember_token_hash', 64)->nullable()->index();
            $table->boolean('remembered')->default(false);
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });

        Notification::fake();
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        Schema::connection('core')->dropIfExists('platform_auth_sessions');
        Schema::connection('core')->dropIfExists('platform_registration_mutexes');
        Schema::connection('core')->dropIfExists('product_subscriptions');
        Schema::connection('core')->dropIfExists('workspace_membership_events');
        Schema::connection('core')->dropIfExists('project_memberships');
        Schema::connection('core')->dropIfExists('projects');
        Schema::connection('core')->dropIfExists('workspace_product_access');
        Schema::connection('core')->dropIfExists('workspace_invitations');
        Schema::connection('core')->dropIfExists('workspace_memberships');
        Schema::connection('core')->dropIfExists('workspaces');
        Schema::connection('core')->dropIfExists('users');

        parent::tearDown();
    }

    public function test_workspace_manager_can_invite_a_teammate_through_the_signal_team_page(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();

        $this->actingAs($owner, 'platform')
            ->get(route('core.workspace.team.index', $workspace))
            ->assertOk()
            ->assertSeeText('Team access')
            ->assertSeeText('Invite a teammate')
            ->assertSee('name="role"', false);

        $this->post(route('core.workspace.team.invitations.store', $workspace), [
            'email' => 'new-member@example.test',
            'role' => 'member',
        ])->assertRedirect(route('core.workspace.team.index', $workspace));

        $invitation = WorkspaceInvitation::query()->sole();
        $this->assertSame('new-member@example.test', $invitation->email_normalized);
        $this->assertSame('pending', $invitation->status);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertSame($owner->getKey(), $invitation->invited_by_user_id);
        Notification::assertSentOnDemandOnce(WorkspaceInvitationNotification::class);
    }

    public function test_invitation_token_is_hashed_and_the_public_review_page_is_private_and_no_store(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $created = app(CreateWorkspaceInvitation::class)->handle($owner, $workspace, 'invitee@example.test', 'viewer');

        $this->assertSame(hash('sha256', $created->token), $created->invitation->token_hash);
        $this->assertNotSame($created->token, $created->invitation->token_hash);

        $this->get(route('platform.workspace-invitations.show', ['token' => $created->token]))
            ->assertOk()
            ->assertSeeText('Join Invitation Workspace')
            ->assertSeeText('invitee@example.test')
            ->assertSeeText('Workspace membership does not automatically grant access to Deployer, Monitor, or Analytics')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $notification = new WorkspaceInvitationNotification('Invitation Workspace', 'Owner', 'viewer', $created->token);
        $mail = $notification->toMail(new AnonymousNotifiable);
        $this->assertSame(
            route('platform.workspace-invitations.show', ['token' => $created->token]),
            $mail->actionUrl,
        );
    }

    public function test_invited_registration_works_when_open_registration_is_closed_and_joins_without_product_access(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $created = app(CreateWorkspaceInvitation::class)->handle($owner, $workspace, 'new-owner@example.test', 'admin');

        $this->get(route('platform.register', ['invitation' => $created->token]))
            ->assertOk()
            ->assertSeeText('Create your Buildpusher account to join as Admin.')
            ->assertSee('readonly', false);

        $response = $this->post(route('platform.register.store'), [
            'invitation' => $created->token,
            'name' => 'New Teammate',
            'email' => 'new-owner@example.test',
            'password' => 'a safe invitation account password',
            'password_confirmation' => 'a safe invitation account password',
        ]);

        $response->assertRedirect(route('core.workspace.dashboard', $workspace));
        $user = PlatformUser::query()->where('email_normalized', 'new-owner@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user, 'platform');
        $this->assertNotNull($user->email_verified_at, 'The single-use invitation token proves access to the invited email address.');
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->sole();
        $this->assertSame('admin', $membership->role);
        $this->assertSame('accepted', $created->invitation->fresh()->status);
        $this->assertSame(1, Workspace::query()->count(), 'Invitation registration must not create a second workspace.');
        $this->assertSame(0, DB::connection('core')->table('workspace_product_access')->count());
        $this->assertSame(0, DB::connection('core')->table('product_subscriptions')->count());
        Notification::assertNotSentTo($user, PlatformVerifyEmail::class);
    }

    public function test_existing_platform_user_can_accept_only_the_invitation_for_their_email(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $invitee = $this->createPlatformUser('invitee@example.test', 'Existing User');
        $created = app(CreateWorkspaceInvitation::class)->handle($owner, $workspace, 'invitee@example.test', 'viewer');

        $this->actingAs($invitee, 'platform')
            ->get(route('platform.workspace-invitations.show', ['token' => $created->token]))
            ->assertOk()
            ->assertSeeText('Accept invitation');

        $this->post(route('platform.workspace-invitations.accept', ['token' => $created->token]))
            ->assertRedirect(route('core.workspace.dashboard', $workspace));

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $invitee->getKey())
            ->sole();
        $this->assertSame('viewer', $membership->role);
        $this->assertSame('accepted', $created->invitation->fresh()->status);
        $this->assertSame(0, DB::connection('core')->table('workspace_product_access')->count());
    }

    public function test_invitation_cannot_be_accepted_by_an_account_with_a_different_email(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $otherUser = $this->createPlatformUser('other@example.test', 'Other User');
        $created = app(CreateWorkspaceInvitation::class)->handle($owner, $workspace, 'invitee@example.test', 'member');

        $this->actingAs($otherUser, 'platform')
            ->post(route('platform.workspace-invitations.accept', ['token' => $created->token]))
            ->assertSessionHasErrors('invitation');

        $this->assertFalse(WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $otherUser->getKey())
            ->exists());
        $this->assertSame('pending', $created->invitation->fresh()->status);
    }

    public function test_expired_invitation_is_not_displayed_or_accepted(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $created = app(CreateWorkspaceInvitation::class)->handle($owner, $workspace, 'invitee@example.test', 'member');
        $created->invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get(route('platform.workspace-invitations.show', ['token' => $created->token]))
            ->assertNotFound();
        $this->get(route('platform.register', ['invitation' => $created->token]))
            ->assertNotFound();
    }

    public function test_non_manager_cannot_invite(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $member = $this->createPlatformUser('member@example.test', 'Member');
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $member->getKey(),
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($member, 'platform')
            ->post(route('core.workspace.team.invitations.store', $workspace), [
                'email' => 'other@example.test',
                'role' => 'admin',
            ])->assertForbidden();

    }

    public function test_workspace_manager_can_revoke_a_pending_invitation(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $invitation = app(CreateWorkspaceInvitation::class)
            ->handle($owner, $workspace, 'other@example.test', 'member')
            ->invitation;

        $this->actingAs($owner, 'platform')
            ->delete(route('core.workspace.team.invitations.destroy', [$workspace, $invitation]))
            ->assertRedirect(route('core.workspace.team.index', $workspace));

        $this->assertSame('revoked', $invitation->fresh()->status);
    }

    public function test_workspace_owner_can_change_a_member_role_and_the_change_is_audited(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $member = $this->createPlatformUser('member@example.test', 'Workspace Member');
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $member->getKey(),
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($owner, 'platform')
            ->get(route('core.workspace.team.index', $workspace))
            ->assertOk()
            ->assertSeeText('Save role')
            ->assertSee('name="role"', false);

        $this->put(route('core.workspace.team.memberships.role.update', [$workspace, $membership]), [
            'role' => 'billing',
        ])->assertRedirect(route('core.workspace.team.index', $workspace));

        $this->assertSame('billing', $membership->fresh()->role);
        $event = WorkspaceMembershipEvent::query()->sole();
        $this->assertSame('role_changed', $event->event);
        $this->assertSame('member', $event->previous_role);
        $this->assertSame('billing', $event->new_role);
        $this->assertSame($owner->getKey(), $event->actor_user_id);
        $this->assertSame($member->getKey(), $event->subject_user_id);
    }

    public function test_admin_cannot_change_workspace_roles_or_remove_another_admin(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $admin = $this->createPlatformUser('admin@example.test', 'Workspace Admin');
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $admin->getKey(),
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $anotherAdmin = $this->createPlatformUser('another-admin@example.test', 'Another Admin');
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $anotherAdmin->getKey(),
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($admin, 'platform')
            ->put(route('core.workspace.team.memberships.role.update', [$workspace, $membership]), ['role' => 'member'])
            ->assertForbidden();
        $this->actingAs($admin, 'platform')
            ->delete(route('core.workspace.team.memberships.destroy', [$workspace, $membership]))
            ->assertForbidden();

        $this->assertSame('admin', $membership->fresh()->role);
        $this->assertSame(0, WorkspaceMembershipEvent::query()->count());
    }

    public function test_admin_can_manage_standard_member_roles_but_cannot_promote_them_to_admin(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $admin = $this->createPlatformUser('admin@example.test', 'Workspace Admin');
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $admin->getKey(),
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $member = $this->createPlatformUser('member@example.test', 'Workspace Member');
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $member->getKey(),
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($admin, 'platform')
            ->put(route('core.workspace.team.memberships.role.update', [$workspace, $membership]), ['role' => 'billing'])
            ->assertRedirect(route('core.workspace.team.index', $workspace));
        $this->actingAs($admin, 'platform')
            ->put(route('core.workspace.team.memberships.role.update', [$workspace, $membership]), ['role' => 'admin'])
            ->assertForbidden();

        $this->assertSame('billing', $membership->fresh()->role);
        $this->assertSame('member', WorkspaceMembershipEvent::query()->sole()->previous_role);
        $this->assertSame('billing', WorkspaceMembershipEvent::query()->sole()->new_role);
        $this->assertSame($admin->getKey(), WorkspaceMembershipEvent::query()->sole()->actor_user_id);
    }

    public function test_revoking_a_member_also_revokes_core_project_memberships_and_product_grants(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $member = $this->createPlatformUser('member@example.test', 'Workspace Member');
        $membership = WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $member->getKey(),
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $grantId = (string) Str::ulid();
        DB::connection('core')->table('workspace_product_access')->insert([
            'id' => $grantId,
            'membership_id' => $membership->getKey(),
            'product' => 'deployer',
            'role' => 'member',
            'status' => 'active',
            'granted_by_user_id' => $owner->getKey(),
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $projectId = (string) Str::ulid();
        DB::connection('core')->table('projects')->insert([
            'id' => $projectId,
            'workspace_id' => $workspace->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $projectMembershipId = (string) Str::ulid();
        DB::connection('core')->table('project_memberships')->insert([
            'id' => $projectMembershipId,
            'project_id' => $projectId,
            'user_id' => $member->getKey(),
            'role' => 'member',
            'status' => 'active',
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner, 'platform')
            ->delete(route('core.workspace.team.memberships.destroy', [$workspace, $membership]))
            ->assertRedirect(route('core.workspace.team.index', $workspace));

        $this->assertSame('revoked', $membership->fresh()->status);
        $this->assertNotNull($membership->fresh()->revoked_at);
        $this->assertSame('revoked', DB::connection('core')->table('workspace_product_access')->where('id', $grantId)->value('status'));
        $this->assertNotNull(DB::connection('core')->table('workspace_product_access')->where('id', $grantId)->value('revoked_at'));
        $this->assertSame('revoked', DB::connection('core')->table('project_memberships')->where('id', $projectMembershipId)->value('status'));

        $event = WorkspaceMembershipEvent::query()->sole();
        $this->assertSame('membership_revoked', $event->event);
        $this->assertSame(['deployer'], $event->metadata['revoked_product_grants']);
        $this->assertSame(1, $event->metadata['revoked_project_memberships']);
    }

    public function test_workspace_owner_cannot_remove_themselves_or_another_owner(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $secondOwner = $this->createPlatformUser('second-owner@example.test', 'Second Owner');
        $secondOwnerMembership = WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $secondOwner->getKey(),
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($owner, 'platform')
            ->delete(route('core.workspace.team.memberships.destroy', [$workspace, $secondOwnerMembership]))
            ->assertForbidden();
        $this->actingAs($owner, 'platform')
            ->delete(route('core.workspace.team.memberships.destroy', [$workspace, $owner->workspaceMemberships()->firstOrFail()]))
            ->assertForbidden();

        $this->assertSame('active', $secondOwnerMembership->fresh()->status);
        $this->assertSame(0, WorkspaceMembershipEvent::query()->count());
    }

    /** @return array{PlatformUser, Workspace} */
    private function createWorkspaceOwner(): array
    {
        $owner = $this->createPlatformUser('owner@example.test', 'Workspace Owner');
        $workspace = Workspace::query()->create([
            'owner_user_id' => $owner->getKey(),
            'name' => 'Invitation Workspace',
            'slug' => 'invitation-workspace',
            'status' => 'active',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $owner->getKey(),
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $workspace];
    }

    private function createPlatformUser(string $email, string $name): PlatformUser
    {
        return PlatformUser::query()->create([
            'id' => (string) Str::ulid(),
            'name' => $name,
            'email' => $email,
            'email_normalized' => strtolower($email),
            'password' => Hash::make('a secure test account password'),
            'password_set_at' => now(),
            'auth_type' => 'password',
            'status' => 'active',
        ]);
    }
}
