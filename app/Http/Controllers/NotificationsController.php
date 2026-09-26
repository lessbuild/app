<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Actions\MarkAllNotificationsRead;
use App\Domain\Notifications\Actions\OpenNotification;
use App\Domain\Notifications\Queries\InboxQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class NotificationsController
{
    public function index(Request $request, #[CurrentUser] User $user, InboxQuery $inbox): View
    {
        $unreadOnly = $request->query('filter') === 'unread';

        return view('notifications.index', [
            'items' => $inbox->handle($user, $unreadOnly)->withQueryString(),
            'unreadOnly' => $unreadOnly,
            'unreadCount' => $inbox->unreadCount($user),
        ]);
    }

    public function open(#[CurrentUser] User $user, string $notification, OpenNotification $open): RedirectResponse
    {
        return redirect($open->handle($user, $notification));
    }

    public function markAllRead(#[CurrentUser] User $user, MarkAllNotificationsRead $markAll): RedirectResponse
    {
        $markAll->handle($user);

        return to_route('notifications.index')->with('status', __('All caught up.'));
    }
}
