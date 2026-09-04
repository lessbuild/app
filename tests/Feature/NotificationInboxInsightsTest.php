<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\FailureNotification;
use App\Notifications\NotificationInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class NotificationInboxInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_filter_aware_metrics_without_rendering_extra_payload_data(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $latestAt = now()->subMinute();
        $this->notification($owner, 'First failed alert', NotificationInbox::STATUS_FAILED, false, now()->subDays(5));
        $this->notification($owner, 'Second failed alert', NotificationInbox::STATUS_FAILED, false, now()->subDays(4));
        $this->notification($owner, 'Recovered alert', NotificationInbox::STATUS_HEALTHY, true, now()->subDays(3));
        $this->notification($owner, 'Information alert', NotificationInbox::STATUS_INFO, false, now()->subDays(2));
        $this->notification($owner, 'Legacy alert', 'legacy', false, $latestAt, 'private-payload-secret');
        $this->notification($other, 'Foreign failed alert', NotificationInbox::STATUS_FAILED, false, now());

        $this->actingAs($owner)->get(route('notifications.index'))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 5
                && $metrics['unread'] === 4
                && $metrics['failed'] === 2
                && $metrics['healthy'] === 1
                && $metrics['info'] === 1
                && $metrics['latest_at']->timestamp === $latestAt->timestamp)
            ->assertSee('Matching alerts')
            ->assertSee('Unread alerts')
            ->assertSee('Failures')
            ->assertSee('Recoveries')
            ->assertSee('Information')
            ->assertSee('Latest matching alert')
            ->assertDontSee('private-payload-secret')
            ->assertDontSee('Foreign failed alert');
    }

    public function test_metrics_apply_all_inbox_filters(): void
    {
        $owner = User::factory()->create();
        $matchingAt = now()->subDay();
        $matching = $this->notification($owner, 'Website recovered cleanly', NotificationInbox::STATUS_HEALTHY, true, $matchingAt);
        $this->notification($owner, 'Website recovered later', NotificationInbox::STATUS_HEALTHY, false, $matchingAt);
        $this->notification($owner, 'Website recovery failed', NotificationInbox::STATUS_FAILED, true, $matchingAt);
        $date = $matchingAt->format('Y-m-d');

        $this->actingAs($owner)->get(route('notifications.index', [
            'search' => 'recovered cleanly',
            'category' => 'website',
            'status' => NotificationInbox::STATUS_HEALTHY,
            'state' => 'read',
            'date_from' => $date,
            'date_to' => $date,
        ]))
            ->assertSuccessful()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === 1
                && $metrics['unread'] === 0
                && $metrics['failed'] === 0
                && $metrics['healthy'] === 1
                && $metrics['info'] === 0
                && $metrics['latest_at']->timestamp === $matchingAt->timestamp)
            ->assertViewHas('notifications', fn ($notifications): bool => $notifications->sole()->id === $matching->id);
    }

    public function test_empty_filtered_inbox_has_explicit_zero_and_unknown_metrics(): void
    {
        $owner = User::factory()->create();
        $this->notification($owner, 'Failed only', NotificationInbox::STATUS_FAILED);

        $this->actingAs($owner)->get(route('notifications.index', [
            'status' => NotificationInbox::STATUS_HEALTHY,
        ]))
            ->assertSuccessful()
            ->assertViewHas('metrics', [
                'total' => 0,
                'unread' => 0,
                'failed' => 0,
                'healthy' => 0,
                'info' => 0,
                'latest_at' => null,
            ])
            ->assertSee('Not available')
            ->assertSee('No matching alert recorded.')
            ->assertSee('No notifications match these filters');
    }

    private function notification(
        User $user,
        string $title,
        string $status,
        bool $read = false,
        mixed $createdAt = null,
        ?string $privatePayload = null,
    ): DatabaseNotification {
        $user->notify(new FailureNotification(
            'website',
            1,
            $title,
            "Message for {$title}",
            $status,
        ));
        $notification = $user->notifications()->where('data->title', $title)->sole();
        $timestamp = $createdAt ?? now();
        $notification->forceFill([
            'data' => [...$notification->data, 'status' => $status, 'internal_context' => $privatePayload],
            'read_at' => $read ? $timestamp : null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->save();

        return $notification->fresh();
    }
}
