<?php

namespace App\Modules\Deployer\Actions\Notification;

use App\Modules\Deployer\Enums\NotificationBulkOperation;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerHistoryAccess;
use Illuminate\Notifications\DatabaseNotification;

class UpdateNotificationStateAction
{
    /** Mark one recipient-owned notification as read. */
    public function markRead(DatabaseNotification $notification): void
    {
        $notification->markAsRead();
    }

    /** Mark one recipient-owned notification as unread. */
    public function markUnread(DatabaseNotification $notification): void
    {
        $notification->markAsUnread();
    }

    /** Mark all unread notifications belonging to the recipient as read. */
    public function markAllRead(User $user): void
    {
        app(DeployerHistoryAccess::class)->notifications($user->unreadNotifications(), $user)->update(['read_at' => now()]);
    }

    /** Delete all read notifications belonging to the recipient and return the affected count. */
    public function clearRead(User $user): int
    {
        return app(DeployerHistoryAccess::class)->notifications($user->readNotifications(), $user)->delete();
    }

    /**
     * Apply one bounded bulk operation only to notifications belonging to the recipient.
     *
     * @param  list<string>  $notificationIds  Validated notification UUIDs.
     */
    public function bulk(User $user, NotificationBulkOperation $operation, array $notificationIds): int
    {
        $notifications = app(DeployerHistoryAccess::class)->notifications($user->notifications(), $user)->whereKey($notificationIds);

        return match ($operation) {
            NotificationBulkOperation::Read => $notifications->update(['read_at' => now()]),
            NotificationBulkOperation::Unread => $notifications->update(['read_at' => null]),
            NotificationBulkOperation::Delete => $notifications->delete(),
        };
    }

    /** Delete one recipient-owned notification. */
    public function delete(DatabaseNotification $notification): void
    {
        $notification->delete();
    }
}
