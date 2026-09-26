<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Domain\Accounts\Actions\AcceptInvitation;
use App\Domain\Accounts\Actions\ChangeMemberRole;
use App\Domain\Accounts\Actions\CreateAccount;
use App\Domain\Accounts\Actions\InviteMember;
use App\Domain\Accounts\Actions\RemoveMember;
use App\Domain\Accounts\Actions\RevokeInvitation;
use App\Domain\Accounts\Actions\SwitchAccount;
use App\Domain\Accounts\Data\InviteMemberData;
use App\Domain\Accounts\Enums\AccountPermission;
use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Events\AccountCreated;
use App\Domain\Accounts\Events\MemberRoleChanged;
use App\Domain\Accounts\Exceptions\AccountRuleViolation;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\AccountInvitation;
use App\Domain\Accounts\Models\Membership;
use App\Domain\Accounts\Notifications\AccountInvitationNotification;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AccountMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_account_makes_the_creator_owner_and_selects_it(): void
    {
        Event::fake([AccountCreated::class]);
        $user = User::factory()->create();

        $account = app(CreateAccount::class)->handle($user, 'Acme Studio');

        $this->assertSame('acme-studio', $account->slug);
        $this->assertSame(AccountRole::Owner, $account->roleOf($user));
        $this->assertSame($account->id, $user->refresh()->current_account_id);
        Event::assertDispatched(AccountCreated::class);

        $second = app(CreateAccount::class)->handle($user, 'Acme Studio');
        $this->assertNotSame($account->slug, $second->slug);
        $this->assertSame($account->id, $user->refresh()->current_account_id, 'A second account does not steal the current selection.');
    }

    public function test_role_permissions_are_scoped(): void
    {
        $this->assertTrue(AccountRole::Owner->allows(AccountPermission::DeleteAccount));
        $this->assertFalse(AccountRole::Admin->allows(AccountPermission::DeleteAccount));
        $this->assertTrue(AccountRole::Billing->allows(AccountPermission::ManageBilling));
        $this->assertFalse(AccountRole::Billing->allows(AccountPermission::ViewProjects));
        $this->assertFalse(AccountRole::Viewer->allows(AccountPermission::ManageProjects));
        $this->assertFalse(AccountRole::Admin->canAssign(AccountRole::Owner));
        $this->assertFalse(AccountRole::Member->canAssign(AccountRole::Viewer));
    }

    public function test_only_members_can_switch_to_an_account(): void
    {
        $user = User::factory()->create();
        $mine = Account::factory()->withMember($user)->create();
        $theirs = Account::factory()->withMember(User::factory()->create())->create();

        app(SwitchAccount::class)->handle($user, $mine);
        $this->assertSame($mine->id, $user->refresh()->current_account_id);

        $this->expectException(AuthorizationException::class);
        app(SwitchAccount::class)->handle($user, $theirs);
    }

    public function test_the_last_owner_cannot_be_demoted_or_removed(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $membership = $this->membership($account, $owner);

        $this->assertRuleViolation(fn () => app(ChangeMemberRole::class)->handle($owner, $membership, AccountRole::Admin));
        $this->assertRuleViolation(fn () => app(RemoveMember::class)->handle($owner, $membership));
        $this->assertSame(AccountRole::Owner, $account->roleOf($owner));

        $second = User::factory()->create();
        $this->join($account, $second, AccountRole::Owner);
        Event::fake([MemberRoleChanged::class]);
        app(ChangeMemberRole::class)->handle($owner, $membership, AccountRole::Admin);
        $this->assertSame(AccountRole::Admin, $account->roleOf($owner));
        Event::assertDispatched(MemberRoleChanged::class);
    }

    public function test_admins_cannot_promote_to_owner_or_touch_owners_and_members_cannot_manage(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $this->join($account, $admin, AccountRole::Admin);
        $this->join($account, $member, AccountRole::Member);

        $this->assertRuleViolation(fn () => app(ChangeMemberRole::class)->handle($admin, $this->membership($account, $member), AccountRole::Owner));
        $this->assertRuleViolation(fn () => app(RemoveMember::class)->handle($admin, $this->membership($account, $owner)));

        app(ChangeMemberRole::class)->handle($admin, $this->membership($account, $member), AccountRole::Viewer);
        $this->assertSame(AccountRole::Viewer, $account->roleOf($member));

        $this->expectException(AuthorizationException::class);
        app(RemoveMember::class)->handle($member, $this->membership($account, $admin));
    }

    public function test_a_member_can_leave_and_their_current_account_moves_on(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $other = Account::factory()->withMember($member)->create();
        $this->join($account, $member, AccountRole::Member);
        $member->forceFill(['current_account_id' => $account->id])->save();

        app(RemoveMember::class)->handle($member, $this->membership($account, $member));

        $this->assertNull($account->roleOf($member));
        $this->assertSame($other->id, $member->refresh()->current_account_id);
    }

    public function test_invitations_are_hashed_single_use_and_bound_to_the_invited_email(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();

        $invitation = app(InviteMember::class)->handle($owner, $account, new InviteMemberData('New.Person@Example.com', AccountRole::Member));

        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, AccountInvitationNotification::class, function (AccountInvitationNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->assertIsString($token);
        $this->assertSame('new.person@example.com', $invitation->email);
        $this->assertNotSame($token, $invitation->getAttribute('token_hash'));

        $stranger = User::factory()->create(['email' => 'someone@example.com']);
        $this->assertRuleViolation(fn () => app(AcceptInvitation::class)->handle($stranger, $token));

        $invitee = User::factory()->create(['email' => 'new.person@example.com']);
        $membership = app(AcceptInvitation::class)->handle($invitee, $token);
        $this->assertSame(AccountRole::Member, $membership->role);
        $this->assertSame($account->id, $invitee->refresh()->current_account_id);

        $this->assertRuleViolation(fn () => app(AcceptInvitation::class)->handle($invitee, $token));
    }

    public function test_reinviting_revokes_the_previous_invitation_and_existing_members_are_rejected(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        $data = new InviteMemberData('person@example.com', AccountRole::Viewer);

        $first = app(InviteMember::class)->handle($owner, $account, $data);
        $second = app(InviteMember::class)->handle($owner, $account, $data);
        $this->assertFalse($first->refresh()->isPending());
        $this->assertTrue($second->refresh()->isPending());

        app(RevokeInvitation::class)->handle($owner, $second);
        $this->assertSame(0, AccountInvitation::query()->pending()->count());

        $this->assertRuleViolation(fn () => app(InviteMember::class)->handle($owner, $account, new InviteMemberData(strtoupper($owner->email), AccountRole::Member)));
    }

    public function test_expired_invitations_cannot_be_accepted(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        app(InviteMember::class)->handle($owner, $account, new InviteMemberData('late@example.com', AccountRole::Member));
        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, AccountInvitationNotification::class, function (AccountInvitationNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->travel(InviteMember::EXPIRES_AFTER_DAYS + 1)->days();

        $this->assertRuleViolation(fn () => app(AcceptInvitation::class)->handle(User::factory()->create(['email' => 'late@example.com']), (string) $token));
    }

    private function join(Account $account, User $user, AccountRole $role): void
    {
        $membership = new Membership;
        $membership->account()->associate($account);
        $membership->user()->associate($user);
        $membership->role = $role;
        $membership->save();
    }

    private function membership(Account $account, User $user): Membership
    {
        return Membership::query()->whereBelongsTo($account)->whereBelongsTo($user)->firstOrFail();
    }

    private function assertRuleViolation(callable $callback): void
    {
        try {
            $callback();
        } catch (AccountRuleViolation) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected an account rule violation.');
    }
}
