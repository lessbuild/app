<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Models\User;
use App\Queries\Notifications\InboxQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowNotificationsController
{
    /**
     * The inbox, all or unread only.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  InboxQuery  $inbox
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, InboxQuery $inbox): View
    {
        $unreadOnly = $request->query('filter') === 'unread';

        return view('notifications.index', [
            'items' => $inbox->handle($user, $unreadOnly)->withQueryString(),
            'unreadOnly' => $unreadOnly,
            'unreadCount' => $inbox->unreadCount($user),
        ]);
    }
}
