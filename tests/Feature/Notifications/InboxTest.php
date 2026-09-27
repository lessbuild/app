<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Accounts\AcceptInvitation;
use App\Actions\Accounts\ChangeMemberRole;
use App\Actions\Accounts\RemoveMember;
use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\AccountRole;
use App\Enums\ApiScope;
use App\Models\Account;
use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class InboxTest extends TestCase
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

    public function test_a_role_change_reaches_the_member_inbox_and_can_be_opened_and_cleared(): void
    {
        $member = User::factory()->create();
        $membership = $this->account->memberships()->forceCreate(['user_id' => $member->id, 'role' => AccountRole::Member]);
        $member->forceFill(['current_account_id' => $this->account->id])->save();

        app(ChangeMemberRole::class)->handle($this->owner, $membership, AccountRole::Admin);
        $this->assertSame(0, $this->owner->notifications()->count(), 'Nobody is told about their own action.');

        $this->actingAs($member)->get('/dashboard')->assertOk()->assertSee(trans_choice('Notifications, :count unread|Notifications, :count unread', 1, ['count' => 1]));
        $this->actingAs($member)->get('/notifications')->assertOk()->assertSee('You are now Administrator in Acme')->assertSee('Olive Owner changed your role.');

        $id = $member->notifications()->sole()->id;
        $this->actingAs($member)->get("/notifications/{$id}")->assertRedirect('/account/members');
        $this->assertSame(0, $member->unreadNotifications()->count());

        app(ChangeMemberRole::class)->handle($this->owner, $membership->refresh(), AccountRole::Viewer);
        $this->actingAs($member)->post('/notifications/read')->assertRedirect('/notifications');
        $this->assertSame(0, $member->unreadNotifications()->count());
        $this->actingAs($this->owner)->get("/notifications/{$id}")->assertNotFound();
    }

    public function test_removal_is_announced_but_leaving_is_not(): void
    {
        $removed = User::factory()->create();
        $leaver = User::factory()->create();
        $removedMembership = $this->account->memberships()->forceCreate(['user_id' => $removed->id, 'role' => AccountRole::Member]);
        $leaverMembership = $this->account->memberships()->forceCreate(['user_id' => $leaver->id, 'role' => AccountRole::Member]);

        app(RemoveMember::class)->handle($this->owner, $removedMembership);
        app(RemoveMember::class)->handle($leaver, $leaverMembership);

        $this->assertSame('You were removed from Acme', $removed->notifications()->sole()->data['title']);
        $this->assertSame(0, $leaver->notifications()->count());
    }

    public function test_the_inviter_hears_when_their_invitation_is_accepted(): void
    {
        $invitation = new AccountInvitation;
        $invitation->forceFill([
            'account_id' => $this->account->id,
            'invited_by_id' => $this->owner->id,
            'email' => 'grace@example.com',
            'role' => AccountRole::Member,
            'token_hash' => AccountInvitation::hashToken('known-token'),
            'expires_at' => now()->addDay(),
        ])->save();
        $token = 'known-token';

        $grace = User::factory()->create(['email' => 'grace@example.com', 'name' => 'Grace']);
        app(AcceptInvitation::class)->handle($grace, $token);

        $this->assertSame('Grace joined Acme', $this->owner->notifications()->sole()->data['title']);
    }

    public function test_owners_are_warned_once_about_tokens_expiring_within_a_week(): void
    {
        $create = app(CreateApiToken::class);
        $create->handle($this->owner, $this->account, new CreateApiTokenData('Soon', [ApiScope::AccountRead], 5));
        $create->handle($this->owner, $this->account, new CreateApiTokenData('Later', [ApiScope::AccountRead], 30));
        $create->handle($this->owner, $this->account, new CreateApiTokenData('Never', [ApiScope::AccountRead], null));

        Artisan::call('api-tokens:warn-expiring');
        Artisan::call('api-tokens:warn-expiring');

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertStringContainsString('“Soon”', $this->owner->notifications()->sole()->data['title']);
    }
}
