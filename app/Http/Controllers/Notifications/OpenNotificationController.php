<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\OpenNotification;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class OpenNotificationController
{
    /**
     * Marks a notification read and goes where it points.
     *
     * @param  User  $user
     * @param  string  $notification
     * @param  OpenNotification  $open
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, string $notification, OpenNotification $open): RedirectResponse
    {
        return redirect($open->handle($user, $notification));
    }
}
