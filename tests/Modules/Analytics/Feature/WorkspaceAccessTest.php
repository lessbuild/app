<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Invitation;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Notifications\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_and_recipient_can_join_workspace(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Client workspace']);
        $workspace->users()->attach($owner, ['role' => WorkspaceRole::Owner->value]);
        $csrf = 'test-token';

        $this->withSession(['_token' => $csrf, 'auth.password_confirmed_at' => now()->timestamp])->actingAs($owner)->post(route('workspaces.invitations.store', $workspace), [
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

        $this->actingAs($recipient)->get(route('invitations.show', $token))->assertOk();
        $this->actingAs($recipient)->withSession(['_token' => 'test-token'])->post(route('invitations.accept', $token), ['_token' => 'test-token'])->assertRedirect(route('dashboard'));
        $this->assertSame(WorkspaceRole::Viewer, $workspace->fresh()->roleFor($recipient));
    }

    public function test_member_cannot_access_another_workspace_site(): void
    {
        $member = User::factory()->create();
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Private workspace']);
        $workspace->users()->attach($owner, ['role' => WorkspaceRole::Owner->value]);
        $site = $workspace->sites()->create(['name' => 'Private site', 'domains' => ['private.example'], 'timezone' => 'UTC']);

        $this->actingAs($member)->get(route('sites.settings', $site))->assertForbidden();
    }

    public function test_viewer_can_read_goals_but_cannot_change_site_configuration(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Read-only workspace']);
        $workspace->users()->attach($owner, ['role' => WorkspaceRole::Owner->value]);
        $workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
        $site = $workspace->sites()->create(['name' => 'Read-only site', 'domains' => ['readonly.example'], 'timezone' => 'UTC']);

        $this->actingAs($viewer)->get(route('goals.index', $site))->assertOk();
        $this->actingAs($viewer)->get(route('sites.settings', $site))->assertForbidden();
        $this->actingAs($viewer)->get(route('sites.setup', $site))->assertForbidden();
        $this->actingAs($viewer)->post(route('goals.store', $site), [
            'name' => 'Blocked goal',
            'kind' => 'path',
            'match_type' => 'exact',
            'match_value' => '/blocked',
        ])->assertForbidden();
    }
}
