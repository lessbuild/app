<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\MarkAllNotificationsRead;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MarkAllNotificationsReadController
{
    /**
     * Mark the whole inbox read, then go back to the inbox, or (from the bell's modal) to the page it was opened on.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  MarkAllNotificationsRead  $markAll
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, MarkAllNotificationsRead $markAll): RedirectResponse
    {
        $markAll->handle($user);

        return ($request->boolean('from_modal') ? back() : to_route('notifications.index'))->with('status', __('All caught up.'));
    }
}
