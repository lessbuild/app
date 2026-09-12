<?php

namespace Tests\Feature;

use App\Jobs\SyncOrganizationSeatQuantityJob;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\PersonalOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_limits' => true, 'billing.enforce_entitlements' => false, 'billing.plans.free.limits.members' => null]);
    }

    public function test_owner_can_invite_a_member_without_exposing_the_token(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        app(PersonalOrganization::class)->ensure($owner);

        $this->actingAs($owner)->post(route('organizations.invitations.store'), [
            'email' => 'Developer@Example.COM',
            'role' => 'developer',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('organization_invitations', ['email' => 'developer@example.com', 'role' => 'developer']);
        Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
    }

    public function test_only_a_current_workspace_manager_can_invite_and_denial_precedes_malformed_input(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $organization->id]);

        $this->actingAs($viewer)->post(route('organizations.invitations.store'), [
            'email' => 'not-an-email',
            'role' => 'not-a-role',
        ])->assertForbidden();
        $this->assertDatabaseCount('organization_invitations', 0);
    }

    public function test_invitation_domain_and_existing_member_rules_reject_without_writes(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $existing = User::factory()->create(['email' => 'existing@example.com']);
        $organization = $owner->currentOrganization;
        $organization->update(['allowed_email_domains' => ['example.com']]);
        $organization->members()->attach($existing, ['role' => 'developer']);

        $this->actingAs($owner)->post(route('organizations.invitations.store'), [
            'email' => 'person@other.example',
            'role' => 'developer',
        ])->assertStatus(422)->assertSee('This email domain is not allowed by the workspace security policy.');
        $this->actingAs($owner)->post(route('organizations.invitations.store'), [
            'email' => 'EXISTING@EXAMPLE.COM',
            'role' => 'developer',
        ])->assertStatus(422)->assertSee('This person is already a member.');
        $this->assertDatabaseCount('organization_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_invitation_acceptance_requires_email_and_token_identity_consumes_once_and_switches_workspace(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'member@example.com']);
        $organization = $owner->currentOrganization;
        $token = 'organization-invitation-token';
        $invitation = $organization->invitations()->create([
            'invited_by' => $owner->id,
            'email' => $member->email,
            'role' => 'operator',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);
        $other = User::factory()->create(['email' => 'other@example.com']);

        $this->actingAs($other)
            ->get(route('organizations.invitations.accept', ['invitation' => $invitation, 'token' => $token]))
            ->assertForbidden();
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertFalse($organization->members()->whereKey($other->id)->exists());

        $this->actingAs($member)
            ->get(route('organizations.invitations.accept', ['invitation' => $invitation, 'token' => $token]))
            ->assertRedirect(route('organizations.index'))
            ->assertSessionHas('success');
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame('operator', $organization->roleFor($member));
        $this->assertSame($organization->id, $member->fresh()->current_organization_id);
        Queue::assertPushed(SyncOrganizationSeatQuantityJob::class, 1);

        $this->actingAs($member)
            ->get(route('organizations.invitations.accept', ['invitation' => $invitation, 'token' => $token]))
            ->assertForbidden();
        Queue::assertPushed(SyncOrganizationSeatQuantityJob::class, 1);
    }

    public function test_invitation_acceptance_rechecks_workspace_seats_before_membership_write(): void
    {
        Queue::fake();
        config(['billing.plans.free.limits.members' => 1]);
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'member@example.com']);
        $organization = $owner->currentOrganization;
        $token = 'full-workspace-invitation-token';
        $invitation = $organization->invitations()->create([
            'invited_by' => $owner->id,
            'email' => $member->email,
            'role' => 'developer',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($member)
            ->get(route('organizations.invitations.accept', ['invitation' => $invitation, 'token' => $token]))
            ->assertSessionHasErrors('plan');
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertFalse($organization->members()->whereKey($member->id)->exists());
        Queue::assertNothingPushed();
    }

    public function test_non_member_cannot_switch_to_another_workspace(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $organization = app(PersonalOrganization::class)->ensure($owner);
        app(PersonalOrganization::class)->ensure($outsider);
        app(PersonalOrganization::class)->ensure($member);
        $organization->members()->attach($member, ['role' => 'developer']);

        $this->actingAs($outsider)->post(route('organizations.switch', $organization))->assertForbidden();

        $this->actingAs($member)
            ->post(route('organizations.switch', $organization))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');
        $this->assertSame($organization->id, $member->fresh()->current_organization_id);
    }

    public function test_viewer_cannot_manage_members(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $target = User::factory()->create();
        $organization = app(PersonalOrganization::class)->ensure($owner);
        $organization->members()->attach($viewer, ['role' => 'viewer']);
        $organization->members()->attach($target, ['role' => 'developer']);
        $viewer->update(['current_organization_id' => $organization->id]);

        $this->actingAs($viewer)->patch(route('organizations.members.update', $target), ['role' => 'admin'])->assertForbidden();
        $this->assertSame('developer', $organization->roleFor($target));
    }

    public function test_manager_member_updates_preserve_owner_protection_and_scoped_not_found_behavior(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $foreign = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($member, ['role' => 'developer']);

        $this->actingAs($owner)
            ->patch(route('organizations.members.update', $owner), ['role' => 'admin'])
            ->assertStatus(422)
            ->assertSee('The owner role cannot be changed.');
        $this->actingAs($owner)
            ->patch(route('organizations.members.update', $foreign), ['role' => 'admin'])
            ->assertNotFound();
        $this->actingAs($owner)
            ->patch(route('organizations.members.update', $member), ['role' => 'admin'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('admin', $organization->fresh()->roleFor($member));
    }

    public function test_manager_member_removal_updates_the_pivot_and_queues_seat_sync(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $foreign = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($member, ['role' => 'developer']);

        $this->actingAs($owner)
            ->delete(route('organizations.members.destroy', $member))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertFalse($organization->fresh()->members()->whereKey($member->id)->exists());
        Queue::assertPushed(SyncOrganizationSeatQuantityJob::class, 1);

        $this->actingAs($owner)
            ->delete(route('organizations.members.destroy', $owner))
            ->assertStatus(422)
            ->assertSee('The workspace owner cannot be removed.');
        $this->actingAs($owner)
            ->delete(route('organizations.members.destroy', $foreign))
            ->assertNotFound();
        Queue::assertPushed(SyncOrganizationSeatQuantityJob::class, 1);
    }

    public function test_admin_can_set_workspace_notification_preferences_but_viewer_cannot(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($admin, ['role' => 'admin']);
        $organization->members()->attach($viewer, ['role' => 'viewer']);

        $admin->update(['current_organization_id' => $organization->id]);
        $this->actingAs($admin)->patch(route('organizations.notification-preferences.update'), [
            'categories' => ['deployment', 'security'],
            'recoveries' => '0',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(['deployment', 'security'], $organization->fresh()->notification_preferences['categories']);
        $this->assertFalse($organization->fresh()->notification_preferences['recoveries']);

        $viewer->update(['current_organization_id' => $organization->id]);
        $this->actingAs($viewer)->patch(route('organizations.notification-preferences.update'), [
            'categories' => ['website'],
            'recoveries' => '1',
        ])->assertForbidden();
    }

    public function test_settings_authorization_precedes_malformed_input_without_writes(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($viewer, ['role' => 'viewer']);
        $viewer->update(['current_organization_id' => $organization->id]);
        $before = $organization->fresh()->only([
            'notification_preferences', 'allowed_ip_ranges', 'allowed_email_domains',
            'require_two_factor', 'session_idle_minutes', 'sso_configuration', 'sso_enforced',
        ]);

        $this->actingAs($viewer)->patch(route('organizations.notification-preferences.update'), [
            'categories' => ['not-a-category'], 'recoveries' => 'not-a-boolean',
        ])->assertForbidden();
        $this->actingAs($viewer)->patch(route('organizations.security-policy.update'), [
            'allowed_ip_ranges' => str_repeat('x', 5001), 'allowed_email_domains' => str_repeat('x', 2001),
            'require_two_factor' => 'not-a-boolean', 'sso_enforced' => 'not-a-boolean',
        ])->assertForbidden();

        $this->assertSame($before, $organization->fresh()->only(array_keys($before)));
    }

    public function test_owner_can_delete_an_empty_workspace_and_receives_a_new_personal_workspace(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->currentOrganization;

        $this->actingAs($owner)->delete(route('organizations.destroy', $organization), [
            'confirmation' => $organization->name,
            'current_password' => 'password',
        ])->assertRedirect(route('organizations.index'));

        $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
        $owner->refresh();
        $this->assertNotNull($owner->current_organization_id);
        $this->assertNotSame($organization->id, $owner->current_organization_id);
        $this->assertSame($owner->id, $owner->currentOrganization->owner_id);
    }

    public function test_workspace_deletion_is_owner_only_and_refuses_to_remove_teammates(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->syncWithoutDetaching([$member->id => ['role' => 'admin']]);
        $member->update(['current_organization_id' => $organization->id]);

        $payload = ['confirmation' => $organization->name, 'current_password' => 'password'];
        $this->actingAs($member)->delete(route('organizations.destroy', $organization), $payload)->assertForbidden();
        $this->actingAs($owner)->delete(route('organizations.destroy', $organization), $payload)->assertStatus(422);
        $this->assertDatabaseHas('organizations', ['id' => $organization->id]);
    }
}
