<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class MembersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Olive Owner']);
        $this->account = Account::factory()->withMember($this->owner)->create(['name' => 'Acme']);
    }

    /**
     * Owners see the team, invite people and revoke invitations.
     */
    public function test_owners_invite_people_and_revoke_invitations(): void
    {
        Notification::fake();

        $this->actingAs($this->owner)->getJson('/api/app/account/members')->assertOk()
            ->assertJsonPath('overview.canManage', true)->assertJsonPath('overview.members.0.name', 'Olive Owner')->assertJsonPath('roles.0.value', 'owner');

        $this->actingAs($this->owner)->postJson('/api/app/account/invitations', ['email' => 'Grace@Example.com', 'role' => 'admin'])
            ->assertOk()->assertJsonPath('redirect', '/account/members')->assertJsonPath('message', __('Invitation sent to :email.', ['email' => 'grace@example.com']));
        Notification::assertSentTo(new AnonymousNotifiable, AccountInvitationNotification::class);

        $invitation = $this->account->invitations()->sole();
        $this->actingAs($this->owner)->getJson('/api/app/account/members')->assertJsonPath('overview.invitations.0.email', 'grace@example.com');

        $this->actingAs($this->owner)->deleteJson("/api/app/account/invitations/{$invitation->id}")->assertOk();
        $this->assertFalse($invitation->refresh()->isPending());
    }

    /**
     * Invitations need a real address and role, and not someone already in the account.
     */
    public function test_invitations_are_validated(): void
    {
        $this->actingAs($this->owner)->postJson('/api/app/account/invitations', ['email' => 'not-an-email', 'role' => 'emperor'])->assertJsonValidationErrors(['email', 'role']);
        $this->actingAs($this->owner)->postJson('/api/app/account/invitations', ['email' => $this->owner->email, 'role' => 'member'])->assertJsonValidationErrors('email');
    }

    /**
     * Owners change people's roles and remove them.
     */
    public function test_owners_change_roles_and_remove_members(): void
    {
        $member = $this->join(User::factory()->create(['name' => 'Max Member']), AccountRole::Member);

        $this->actingAs($this->owner)->putJson("/api/app/account/members/{$member->id}", ['role' => 'admin'])->assertOk()->assertJsonPath('redirect', '/account/members');
        $this->assertSame(AccountRole::Admin, $member->refresh()->role);

        $this->actingAs($this->owner)->deleteJson("/api/app/account/members/{$member->id}")->assertOk()->assertJsonPath('redirect', '/account/members');
        $this->assertNull(Membership::query()->find($member->id));
    }

    /**
     * Admins can't change owners, and the last owner can't leave.
     */
    public function test_admins_cannot_touch_owners_and_the_last_owner_cannot_leave(): void
    {
        $admin = User::factory()->create();
        $this->join($admin, AccountRole::Admin);
        $ownerMembership = $this->account->memberships()->where('user_id', $this->owner->id)->sole();

        $manageable = null;
        foreach ((array) $this->actingAs($admin)->getJson('/api/app/account/members')->assertOk()->json('overview.members') as $row) {
            if (is_array($row) && ($row['membershipId'] ?? null) === $ownerMembership->id) {
                $manageable = $row['manageable'] ?? null;
            }
        }
        $this->assertFalse($manageable);
        $this->actingAs($admin)->putJson("/api/app/account/members/{$ownerMembership->id}", ['role' => 'viewer'])->assertJsonValidationErrors('role');
        $this->assertSame(AccountRole::Owner, $ownerMembership->refresh()->role);

        $this->actingAs($this->owner)->deleteJson("/api/app/account/members/{$ownerMembership->id}")->assertJsonValidationErrors('role');
    }

    /**
     * Members see the team and can leave, but can't manage it.
     */
    public function test_members_can_see_the_team_and_leave_but_not_manage_it(): void
    {
        $user = User::factory()->create();
        $membership = $this->join($user, AccountRole::Member);

        $this->actingAs($user)->getJson('/api/app/account/members')->assertOk()->assertJsonPath('overview.canManage', false)->assertJsonPath('overview.invitations', []);
        $this->actingAs($user)->postJson('/api/app/account/invitations', ['email' => 'x@example.com', 'role' => 'viewer'])->assertForbidden();

        $this->actingAs($user)->deleteJson("/api/app/account/members/{$membership->id}")->assertOk()->assertJsonPath('redirect', '/dashboard');
        $this->assertNull(Membership::query()->find($membership->id));
    }

    /**
     * Memberships of other accounts aren't found.
     */
    public function test_memberships_of_other_accounts_are_not_found(): void
    {
        $stranger = User::factory()->create();
        $elsewhere = Account::factory()->withMember($stranger)->create()->memberships()->sole();

        $this->actingAs($this->owner)->deleteJson("/api/app/account/members/{$elsewhere->id}")->assertNotFound();
        $this->actingAs($this->owner)->putJson("/api/app/account/members/{$elsewhere->id}", ['role' => 'viewer'])->assertNotFound();
    }

    /**
     * Add someone to the test account in a role.
     *
     * @param  User  $user
     * @param  AccountRole  $role
     * @return Membership
     */
    private function join(User $user, AccountRole $role): Membership
    {
        $user->forceFill(['current_account_id' => $this->account->id])->save();

        return $this->account->memberships()->forceCreate(['user_id' => $user->id, 'role' => $role]);
    }
}
