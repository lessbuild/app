<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\User;

final class OpenNotification
{
    /**
     * Mark one of the user's notifications read and return where it points (always a path on this app).
     *
     * @param  User  $user
     * @param  string  $id
     * @return string
     */
    public function handle(User $user, string $id): string
    {
        $notification = $user->notifications()->findOrFail($id);
        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;

        return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : '/dashboard';
    }
}
