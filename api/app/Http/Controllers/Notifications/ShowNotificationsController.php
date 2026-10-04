<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Requests\Notifications\InboxRequest;
use App\Models\User;
use App\Queries\Notifications\InboxQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/notifications?filter=unread&type=&q=&cursor=&per=`. */
final class ShowNotificationsController
{
    /**
     * Return a page of the person's notifications (newest first) with the filters that chose it, the kinds they can
     * filter by, and how many are unread. `per` asks for a shorter page, as the bell's panel does.
     *
     * @param  InboxRequest  $request
     * @param  User  $user
     * @param  InboxQuery  $inbox
     * @return JsonResponse
     */
    public function __invoke(InboxRequest $request, #[CurrentUser] User $user, InboxQuery $inbox): JsonResponse
    {
        $types = $inbox->types($user);
        $filters = $request->filters(array_keys($types));
        $items = $inbox->handle($user, $filters, min(30, max(5, $request->integer('per', 30))));

        return response()->json([
            'items' => $items->items(),
            'nextCursor' => $items->nextCursor()?->encode(),
            'previousCursor' => $items->previousCursor()?->encode(),
            'filters' => ['unread' => $filters->unreadOnly, 'type' => $filters->type, 'q' => $filters->search],
            'types' => $types,
            'unreadCount' => $inbox->unreadCount($user),
        ]);
    }
}
