<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use App\Notifications\NotificationInbox;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSecurityActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_security_changes_create_metadata_only_owner_activity(): void
    {
        $user = User::factory()->create([
            'email' => 'original@example.test',
            'password' => Hash::make('current-password'),
            'github_id' => 'private-github-identity',
            'auth_type' => 'github',
        ]);

        $this->actingAs($user)->patch(route('account.profile.update'), [
            'name' => 'Updated Owner',
            'email' => 'updated@example.test',
            'current_password' => 'current-password',
        ])->assertSessionHas('profile_status');
        $this->patch(route('account.password.update'), [
            'current_password' => 'current-password',
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertSessionHas('password_status');
        $this->post(route('account.sessions.revoke'), [
            'current_password' => 'replacement-password',
        ])->assertSessionHas('sessions_status');
        $this->delete(route('account.social.destroy', 'github'), [
            'social_provider' => 'github',
            'current_password' => 'replacement-password',
        ])
            ->assertSessionHas('social_status', 'GitHub disconnected.');

        $events = $user->events()->where('category', 'account')->oldest()->get();
        $this->assertSame([
            'Account email address was changed and requires verification.',
            'Account password was changed.',
            'Other browser sessions were logged out.',
            'GitHub sign-in was disconnected.',
        ], $events->pluck('event')->all());
        $this->assertTrue($events->every(fn (Event $event): bool => $event->parentable_type === User::class
            && $event->parentable_id === $user->id));
        $this->assertNull($events->last()->load('parentable')->url());

        $serialized = $events
            ->map(fn (Event $event): array => $event->only([
                'event',
                'category',
                'parentable_type',
                'parentable_id',
            ]))
            ->toJson();
        $this->assertStringNotContainsString('original@example.test', $serialized);
        $this->assertStringNotContainsString('updated@example.test', $serialized);
        $this->assertStringNotContainsString('current-password', $serialized);
        $this->assertStringNotContainsString('replacement-password', $serialized);
        $this->assertStringNotContainsString('private-github-identity', $serialized);

        $notifications = $user->notifications()->where('data->category', 'account')->oldest()->get();
        $this->assertEqualsCanonicalizing($events->pluck('event')->all(), $notifications->pluck('data.message')->all());
        $this->assertTrue($notifications->every(fn ($notification): bool => $notification->type === AccountSecurityNotification::class
            && $notification->data['title'] === 'Account security changed'
            && $notification->data['status'] === NotificationInbox::STATUS_INFO
            && $notification->data['resource_id'] === $user->id));
        $notificationData = $notifications->pluck('data')->toJson();
        $this->assertStringNotContainsString('original@example.test', $notificationData);
        $this->assertStringNotContainsString('updated@example.test', $notificationData);
        $this->assertStringNotContainsString('current-password', $notificationData);
        $this->assertStringNotContainsString('replacement-password', $notificationData);
        $this->assertStringNotContainsString('private-github-identity', $notificationData);
    }

    public function test_account_activity_filter_and_export_are_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recorder = app(ActivityRecorder::class);
        $ownerEvent = $recorder->recordAccount($owner, 'Owner reviewed account security.');
        $recorder->recordAccount($other, 'Foreign account security event.');
        $ownerNotification = $owner->notifications()->sole();

        $this->actingAs($owner)->get(route('activity.index', ['category' => 'account']))
            ->assertSuccessful()
            ->assertViewHas('events', fn ($events): bool => $events->count() === 1
                && $events->sole()->is($ownerEvent))
            ->assertSee('Owner reviewed account security.')
            ->assertDontSee('Foreign account security event.');

        $content = $this->get(route('activity.export', ['category' => 'account']))
            ->assertSuccessful()
            ->streamedContent();
        $this->assertStringContainsString('Owner reviewed account security.', $content);
        $this->assertStringContainsString(',account,', $content);
        $this->assertStringContainsString(',User,'.$owner->id.',', $content);
        $this->assertStringNotContainsString('Foreign account security event.', $content);

        $this->get(route('notifications.index', ['category' => 'account']))
            ->assertSuccessful()
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->count() === 1
                && $notifications->sole()->id === $ownerNotification->id)
            ->assertSee('Owner reviewed account security.')
            ->assertSee('border-blue-300', false)
            ->assertDontSee('Foreign account security event.');
        $notificationExport = $this->get(route('notifications.export', ['category' => 'account']))
            ->assertSuccessful()
            ->streamedContent();
        $this->assertStringContainsString(',account,', $notificationExport);
        $this->assertStringContainsString(',info,unread,', $notificationExport);
        $this->assertStringNotContainsString('Foreign account security event.', $notificationExport);

        $this->post(route('notifications.read', $ownerNotification))
            ->assertRedirect(route('account.index'));
        $this->assertNotNull($ownerNotification->fresh()->read_at);
    }
}
