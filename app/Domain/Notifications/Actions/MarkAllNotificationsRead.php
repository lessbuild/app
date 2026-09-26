<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Identity\Models\User;

final class MarkAllNotificationsRead
{
    public function handle(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
