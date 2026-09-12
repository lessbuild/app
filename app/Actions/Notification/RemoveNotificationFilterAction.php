<?php

namespace App\Actions\Notification;

use App\Models\User;

class RemoveNotificationFilterAction
{
    /** Remove one saved inbox preset from the request user's preferences. */
    public function handle(User $user, string $filter): void
    {
        $preferences = $user->preferences ?? [];
        $preferences['notification_saved_filters'] = collect($preferences['notification_saved_filters'] ?? [])
            ->reject(fn (array $saved): bool => hash_equals((string) $saved['id'], $filter))
            ->values()
            ->all();

        $user->update(['preferences' => $preferences]);
    }
}
