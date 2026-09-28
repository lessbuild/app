<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Requests\Notifications\InboxRequest;
use App\Models\User;
use App\Queries\Notifications\InboxQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowNotificationsController
{
    /**
     * Show the inbox, narrowed to unread, one kind of notification or a search.
     *
     * @param  InboxRequest  $request
     * @param  User  $user
     * @param  InboxQuery  $inbox
     * @return View
     */
    public function __invoke(InboxRequest $request, #[CurrentUser] User $user, InboxQuery $inbox): View
    {
        $types = $inbox->types($user);
        $filters = $request->filters(array_keys($types));

        return view('notifications.index', [
            'items' => $inbox->handle($user, $filters)->withQueryString(),
            'filters' => $filters,
            'types' => $types,
            'unreadOnly' => $filters->unreadOnly,
            'unreadCount' => $inbox->unreadCount($user),
        ]);
    }
}
