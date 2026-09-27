<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\MarkAllNotificationsRead;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class MarkAllNotificationsReadController
{
    public function __invoke(#[CurrentUser] User $user, MarkAllNotificationsRead $markAll): RedirectResponse
    {
        $markAll->handle($user);

        return to_route('notifications.index')->with('status', __('All caught up.'));
    }
}
