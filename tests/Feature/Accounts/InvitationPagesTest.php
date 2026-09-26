<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Domain\Accounts\Actions\InviteMember;
use App\Domain\Accounts\Data\InviteMemberData;
use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Notifications\AccountInvitationNotification;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class InvitationPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_the_invitation_and_returns_to_it_after_signing_in(): void
    {
        [$account, $token] = $this->invite('guest@example.com');

        $this->get("/invitations/{$token}")->assertOk()->assertSee('Join '.$account->name)->assertSee('guest@example.com');
        $this->assertSame(url("/invitations/{$token}"), session('url.intended'));
    }

    public function test_the_invited_user_accepts_and_lands_in_the_account(): void
    {
        [$account, $token] = $this->invite('member@example.com');
        $invitee = User::factory()->create(['email' => 'member@example.com']);

        $this->actingAs($invitee)->post("/invitations/{$token}")->assertRedirect('/dashboard');

        $this->assertSame(AccountRole::Member, $account->roleOf($invitee));
        $this->assertSame($account->id, $invitee->refresh()->current_account_id);
    }

    public function test_a_different_signed_in_user_is_refused_with_a_message(): void
    {
        [$account, $token] = $this->invite('member@example.com');
        $someoneElse = User::factory()->create();

        $this->actingAs($someoneElse)->from("/invitations/{$token}")->post("/invitations/{$token}")
            ->assertRedirect("/invitations/{$token}")
            ->assertSessionHasErrors('invitation');
        $this->assertNull($account->roleOf($someoneElse));
    }

    public function test_unknown_tokens_show_an_unavailable_message(): void
    {
        $this->get('/invitations/not-a-real-token')->assertOk()->assertSee('This invitation isn’t available');
    }

    /** @return array{Account, string} */
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
