<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\InviteMember;
use App\Data\Accounts\InviteMemberData;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class InvitationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A guest sees who the invitation is for, to sign in or sign up with that address.
     */
    public function test_guests_see_the_invitation(): void
    {
        [$account, $token] = $this->invite('guest@example.com');

        $this->getJson("/api/app/invitations/{$token}")->assertOk()->assertJsonPath('invitation.accountName', $account->name)->assertJsonPath('invitation.email', 'guest@example.com');
    }

    /**
     * The invited person accepts and lands in the account.
     */
    public function test_the_invited_user_accepts_and_lands_in_the_account(): void
    {
        [$account, $token] = $this->invite('member@example.com');
        $invitee = User::factory()->create(['email' => 'member@example.com']);

        $this->actingAs($invitee)->postJson("/api/app/invitations/{$token}")->assertOk()->assertJsonPath('redirect', '/dashboard');

        $this->assertSame(AccountRole::Member, $account->roleOf($invitee));
        $this->assertSame($account->id, $invitee->refresh()->current_account_id);
    }

    /**
     * Someone else signed in is refused, with a message saying why.
     */
    public function test_a_different_signed_in_user_is_refused_with_a_message(): void
    {
        [$account, $token] = $this->invite('member@example.com');
        $someoneElse = User::factory()->create();

        $this->actingAs($someoneElse)->postJson("/api/app/invitations/{$token}")->assertJsonValidationErrors('invitation');
        $this->assertNull($account->roleOf($someoneElse));
    }

    /**
     * Unknown tokens have no invitation.
     */
    public function test_unknown_tokens_have_no_invitation(): void
    {
        $this->getJson('/api/app/invitations/not-a-real-token')->assertOk()->assertJsonPath('invitation', null);
    }

    /**
     * Invite an address to a new account as a member.
     *
     * @param  string  $email
     * @return array{Account, string} The account and the invitation's token.
     */
    private function invite(string $email): array
    {
        Notification::fake();
        $owner = User::factory()->create();
        $account = Account::factory()->withMember($owner)->create();
        app(InviteMember::class)->handle($owner, $account, new InviteMemberData($email, AccountRole::Member));

        $token = '';
        Notification::assertSentTo(new AnonymousNotifiable, AccountInvitationNotification::class, function (AccountInvitationNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        return [$account, $token];
    }
}
