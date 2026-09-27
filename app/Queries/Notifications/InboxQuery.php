<?php

declare(strict_types=1);

namespace App\Queries\Notifications;

use App\Data\Notifications\InboxItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Notifications\DatabaseNotification;

final class InboxQuery
{
    /**
     * The person's notifications, newest first and cursor-paginated, optionally only unread ones.
     *
     * @return CursorPaginator<int, InboxItem> newest first
     */
    public function handle(User $user, bool $unreadOnly = false, int $perPage = 30): CursorPaginator
    {
        return $user->notifications()
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->through(fn (DatabaseNotification $notification): InboxItem => new InboxItem(
                id: (string) $notification->id,
                title: is_string($notification->data['title'] ?? null) ? $notification->data['title'] : __('Notification'),
                body: is_string($notification->data['body'] ?? null) ? $notification->data['body'] : '',
                read: $notification->read_at !== null,
                at: CarbonImmutable::instance($notification->created_at ?? now()),
            ));
    }

    /**
     * How many are unread, for the badge in the shell.
     */
    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }
}
