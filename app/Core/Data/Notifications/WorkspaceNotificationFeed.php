<?php

namespace App\Core\Data\Notifications;

use Illuminate\Support\Collection;

/** @param Collection<int, WorkspaceNotification> $notifications
 * @param Collection<int, string> $unavailableProducts
 */
final readonly class WorkspaceNotificationFeed
{
    public function __construct(
        public Collection $notifications,
        public Collection $unavailableProducts,
        public int $unreadCount,
    ) {}
}
