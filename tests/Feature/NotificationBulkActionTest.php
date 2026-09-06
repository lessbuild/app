<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\FailureNotification;
use App\Notifications\NotificationInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class NotificationBulkActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_mark_selected_notifications_as_read_and_unread(): void
    {
        $owner = User::factory()->create();
        $first = $this->notification($owner, 'First alert');
        $second = $this->notification($owner, 'Second alert');

        $this->actingAs($owner)->patch(route('notifications.bulk'), [
            'action' => 'read',
            'notifications' => [$first->id, $second->id],
        ])->assertRedirect()->assertSessionHas('success', '2 notifications marked as read.');

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNotNull($second->fresh()->read_at);

        $this->actingAs($owner)->patch(route('notifications.bulk'), [
            'action' => 'unread',
            'notifications' => [$first->id],
        ])->assertRedirect()->assertSessionHas('success', '1 notification marked as unread.');

        $this->assertNull($first->fresh()->read_at);
        $this->assertNotNull($second->fresh()->read_at);
    }

    public function test_owner_can_delete_selected_notifications_without_affecting_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owned = $this->notification($owner, 'Owned alert');
        $foreign = $this->notification($other, 'Foreign alert');

        $this->actingAs($owner)->patch(route('notifications.bulk'), [
            'action' => 'delete',
            'notifications' => [$owned->id, $foreign->id],
        ])->assertRedirect()->assertSessionHas('success', '1 notification deleted.');

        $this->assertDatabaseMissing('notifications', ['id' => $owned->id]);
        $this->assertDatabaseHas('notifications', ['id' => $foreign->id]);
    }

    public function test_bulk_action_requires_a_supported_action_and_one_to_twenty_five_unique_ids(): void
    {
        $owner = User::factory()->create();
        $notification = $this->notification($owner, 'Kept alert');

        $this->actingAs($owner)->from(route('notifications.index'))->patch(route('notifications.bulk'), [
            'action' => 'archive',
            'notifications' => [$notification->id, $notification->id],
        ])->assertRedirect(route('notifications.index'))
            ->assertSessionHasErrors(['action', 'notifications.1']);

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_inbox_renders_page_scoped_bulk_controls(): void
    {
        $owner = User::factory()->create();
        $notification = $this->notification($owner, 'Selectable alert');

        $this->actingAs($owner)->get(route('notifications.index'))
            ->assertSuccessful()
            ->assertSee(route('notifications.bulk'), false)
            ->assertSee('Select page')
            ->assertSee('Mark read')
            ->assertSee('Mark unread')
            ->assertSee('Delete selected')
            ->assertSee('form="notification-bulk-form"', false)
            ->assertSee('value="'.$notification->id.'"', false);
    }

    public function test_user_can_save_apply_and_remove_a_notification_filter(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('notifications.saved-filters.store', [
            'category' => 'website', 'status' => 'failed', 'state' => 'unread',
        ]), ['name' => 'Website incidents'])->assertRedirect()->assertSessionHas('success');

        $saved = $owner->fresh()->preferences['notification_saved_filters'][0];
        $this->get(route('notifications.index'))->assertOk()->assertSee('Website incidents');
        $this->delete(route('notifications.saved-filters.destroy', $saved['id']))->assertRedirect();
        $this->assertSame([], $owner->fresh()->preferences['notification_saved_filters']);
    }

    private function notification(User $user, string $title): DatabaseNotification
    {
        $user->notify(new FailureNotification(
            'website',
            1,
            $title,
            "Message for {$title}",
            NotificationInbox::STATUS_FAILED,
        ));

        return $user->notifications()->where('data->title', $title)->sole();
    }
}
