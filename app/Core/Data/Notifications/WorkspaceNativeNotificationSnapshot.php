<?php

namespace App\Core\Data\Notifications;

use Illuminate\Support\Collection;

/** @param Collection<int, WorkspaceNotification> $notifications */
final readonly class WorkspaceNativeNotificationSnapshot
{
    public function __construct(
        public Collection $notifications,
        public bool $available = true,
    ) {}
}
