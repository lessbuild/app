<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\User;

final class MarkAllNotificationsRead
{
    /**
     * Marks every unread notification read and returns how many there were.
     */
    public function handle(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
