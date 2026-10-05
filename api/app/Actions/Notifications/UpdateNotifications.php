<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\User;
use InvalidArgumentException;

final class UpdateNotifications
{
    /**
     * Mark some of the person's own notifications read or unread, or delete them; or, with no ids and "clear-read",
     * delete every notification they've read. Notifications that aren't theirs are left alone.
     *
     * @param  User  $user
     * @param  string  $action  "read", "unread", "delete" or "clear-read".
     * @param  list<string>  $ids  The notifications to change (ignored for "clear-read").
     * @return int How many changed.
     */
    public function handle(User $user, string $action, array $ids = []): int
    {
        $chosen = $user->notifications()->whereIn('id', $ids);

        return match ($action) {
            'read' => $chosen->whereNull('read_at')->update(['read_at' => now()]),
            'unread' => $chosen->whereNotNull('read_at')->update(['read_at' => null]),
            'delete' => $chosen->delete(),
            'clear-read' => $user->readNotifications()->delete(),
            default => throw new InvalidArgumentException('Unknown notification action '.$action.'.'),
        };
    }
}
