<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\PersonalOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
            'email' => 'developer@example.com',
            'role' => 'developer',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('organization_invitations', ['email' => 'developer@example.com', 'role' => 'developer']);
        Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
    }

    public function test_non_member_cannot_switch_to_another_workspace(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $organization = app(PersonalOrganization::class)->ensure($owner);
        app(PersonalOrganization::class)->ensure($outsider);

        $this->actingAs($outsider)->post(route('organizations.switch', $organization))->assertForbidden();
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
