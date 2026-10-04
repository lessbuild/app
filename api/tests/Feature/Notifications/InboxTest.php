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
use App\Notifications\NewFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
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

    /**
     * A role change reaches the member's inbox (and the shell's count), and can be opened and cleared.
     */
    public function test_a_role_change_reaches_the_member_inbox_and_can_be_opened_and_cleared(): void
    {
        $member = User::factory()->create();
        $membership = $this->account->memberships()->forceCreate(['user_id' => $member->id, 'role' => AccountRole::Member]);
        $member->forceFill(['current_account_id' => $this->account->id])->save();

        app(ChangeMemberRole::class)->handle($this->owner, $membership, AccountRole::Admin);
        $this->assertSame(0, $this->owner->notifications()->count(), 'Nobody is told about their own action.');

        $this->actingAs($member)->getJson('/api/app/shell')->assertOk()->assertJsonPath('unreadNotifications', 1);
        $this->actingAs($member)->getJson('/api/app/notifications')->assertOk()->assertJsonPath('unreadCount', 1)
            ->assertJsonPath('items.0.title', 'You are now Administrator in Acme')->assertJsonPath('items.0.body', 'Olive Owner changed your role.');
        $this->actingAs($member)->getJson('/api/app/notifications?per=10')->assertOk()->assertJsonCount(1, 'items');

        $id = $member->notifications()->sole()->id;
        $this->actingAs($member)->postJson("/api/app/notifications/{$id}/open")->assertOk()->assertJsonPath('redirect', '/account/members');
        $this->assertSame(0, $member->unreadNotifications()->count());

        app(ChangeMemberRole::class)->handle($this->owner, $membership->refresh(), AccountRole::Viewer);
        $this->actingAs($member)->postJson('/api/app/notifications/read')->assertOk()->assertJsonPath('message', __('All caught up.'));
        $this->assertSame(0, $member->unreadNotifications()->count());
        $this->actingAs($this->owner)->postJson("/api/app/notifications/{$id}/open")->assertNotFound();
    }

    /**
     * Removal is announced but leaving is not.
     */
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

    /**
     * The inviter hears when their invitation is accepted.
     */
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

    /**
     * Owners are warned once about tokens expiring within a week.
     */
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

    /**
     * The inbox filters by kind and words, and exports as CSV safe for spreadsheets.
     */
    public function test_the_inbox_filters_by_kind_and_words_and_exports(): void
    {
        $this->notify('Deploy finished', 'Shop is live', read: true);
        $this->notify('=HYPERLINK("x")', 'Formula', read: false);
        $this->notify('New feedback: An idea', 'Dark mode', read: false, type: NewFeedback::class);

        $this->actingAs($this->owner)->getJson('/api/app/notifications?type='.urlencode(NewFeedback::class))->assertOk()->assertSee('Dark mode')->assertDontSee('Shop is live')->assertSee('New feedback');
        $this->actingAs($this->owner)->getJson('/api/app/notifications?q=shop')->assertOk()->assertSee('Shop is live')->assertDontSee('Dark mode');
        $this->actingAs($this->owner)->getJson('/api/app/notifications?filter=unread&q=shop')->assertOk()->assertDontSee('Shop is live');

        $csv = $this->actingAs($this->owner)->get('/api/app/notifications/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('Shop is live', $csv);
    }

    /**
     * Put a notification in the owner's inbox.
     *
     * @param  string  $title
     * @param  string  $body
     * @param  bool  $read
     * @param  string  $type
     * @return void
     */
    private function notify(string $title, string $body, bool $read, string $type = 'App\Notifications\BuildFinished'): void
    {
        DatabaseNotification::query()->forceCreate([
            'id' => (string) Str::uuid(), 'type' => $type, 'notifiable_type' => $this->owner->getMorphClass(), 'notifiable_id' => $this->owner->id,
            'data' => ['title' => $title, 'body' => $body, 'url' => '/dashboard', 'account_id' => $this->account->id], 'read_at' => $read ? now() : null,
        ]);
    }
}
