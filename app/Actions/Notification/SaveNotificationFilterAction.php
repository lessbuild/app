<?php

namespace App\Actions\Notification;

use App\Models\User;

class SaveNotificationFilterAction
{
    /**
     * Persist one normalized inbox preset, replacing a case-insensitive name and retaining the nine previous presets.
     *
     * @param  array{search: ?string, category: ?string, status: ?string, state: ?string, date_from: ?string, date_to: ?string}  $filters  Validated inbox criteria.
     */
    public function handle(User $user, string $name, array $filters): void
    {
        $preferences = $user->preferences ?? [];
        $saved = collect($preferences['notification_saved_filters'] ?? [])
            ->reject(fn (array $filter): bool => mb_strtolower($filter['name']) === mb_strtolower($name))
            ->take(9)
            ->values();
        $saved->prepend([
            'id' => (string) str()->uuid(),
            'name' => $name,
            'filters' => array_filter($filters, fn ($value) => $value !== null),
        ]);
        $preferences['notification_saved_filters'] = $saved->all();

        $user->update(['preferences' => $preferences]);
    }
}
