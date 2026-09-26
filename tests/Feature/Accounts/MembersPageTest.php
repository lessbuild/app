<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Membership;
use App\Domain\Accounts\Notifications\AccountInvitationNotification;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class MembersPageTest extends TestCase
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

    public function test_owners_invite_people_and_revoke_invitations(): void
    {
        Notification::fake();

        $this->actingAs($this->owner)->get('/account')->assertRedirect('/account/members');
        $this->actingAs($this->owner)->get('/account/members')->assertOk()->assertSee(__('Send invitation'))->assertSee('Olive Owner');

        $this->actingAs($this->owner)->post('/account/invitations', ['email' => 'Grace@Example.com', 'role' => 'admin'])
            ->assertRedirect('/account/members')
            ->assertSessionHas('status');
        Notification::assertSentTo(new AnonymousNotifiable, AccountInvitationNotification::class);

        $invitation = $this->account->invitations()->sole();
        $this->actingAs($this->owner)->get('/account/members')->assertSee('grace@example.com')->assertSee(route('account.invitations.destroy', $invitation->id), false);

        $this->actingAs($this->owner)->delete("/account/invitations/{$invitation->id}")->assertRedirect('/account/members');
        $this->assertFalse($invitation->refresh()->isPending());
    }

    public function test_invitations_are_validated(): void
    {
        $this->actingAs($this->owner)->post('/account/invitations', ['email' => 'not-an-email', 'role' => 'emperor'])->assertSessionHasErrors(['email', 'role']);
        $this->actingAs($this->owner)->post('/account/invitations', ['email' => $this->owner->email, 'role' => 'member'])->assertSessionHasErrors('email');
    }

    public function test_owners_change_roles_and_remove_members(): void
    {
        $member = $this->join(User::factory()->create(['name' => 'Max Member']), AccountRole::Member);

        $this->actingAs($this->owner)->put("/account/members/{$member->id}", ['role' => 'admin'])->assertRedirect('/account/members');
        $this->assertSame(AccountRole::Admin, $member->refresh()->role);

        $this->actingAs($this->owner)->delete("/account/members/{$member->id}")->assertRedirect('/account/members');
        $this->assertNull(Membership::query()->find($member->id));
    }

    public function test_admins_cannot_touch_owners_and_the_last_owner_cannot_leave(): void
    {
        $admin = User::factory()->create();
        $this->join($admin, AccountRole::Admin);
        $ownerMembership = $this->account->memberships()->where('user_id', $this->owner->id)->sole();

        $this->actingAs($admin)->get('/account/members')->assertOk()->assertDontSee(route('account.members.update', $ownerMembership->id), false);
        $this->actingAs($admin)->put("/account/members/{$ownerMembership->id}", ['role' => 'viewer'])->assertSessionHasErrors('role');
        $this->assertSame(AccountRole::Owner, $ownerMembership->refresh()->role);

        $this->actingAs($this->owner)->delete("/account/members/{$ownerMembership->id}")->assertSessionHasErrors('role');
    }

    public function test_members_can_see_the_team_and_leave_but_not_manage_it(): void
    {
        $user = User::factory()->create();
        $membership = $this->join($user, AccountRole::Member);

        $this->actingAs($user)->get('/account/members')->assertOk()->assertSee('Olive Owner')->assertDontSee(__('Send invitation'));
        $this->actingAs($user)->post('/account/invitations', ['email' => 'x@example.com', 'role' => 'viewer'])->assertForbidden();

        $this->actingAs($user)->delete("/account/members/{$membership->id}")->assertRedirect('/dashboard');
        $this->assertNull(Membership::query()->find($membership->id));
    }

    public function test_memberships_of_other_accounts_are_not_found(): void
    {
        $stranger = User::factory()->create();
        $elsewhere = Account::factory()->withMember($stranger)->create()->memberships()->sole();

        $this->actingAs($this->owner)->delete("/account/members/{$elsewhere->id}")->assertNotFound();
        $this->actingAs($this->owner)->put("/account/members/{$elsewhere->id}", ['role' => 'viewer'])->assertNotFound();
    }

    private function join(User $user, AccountRole $role): Membership
    {
        $user->forceFill(['current_account_id' => $this->account->id])->save();

        return $this->account->memberships()->forceCreate(['user_id' => $user->id, 'role' => $role]);
    }
}
