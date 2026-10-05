<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\UpdateNotifications;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `POST /api/app/notifications/bulk`. */
final class UpdateNotificationsController
{
    /**
     * Mark the chosen notifications read or unread, or delete them (up to 50 at once).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  UpdateNotifications  $update
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, UpdateNotifications $update): JsonResponse
    {
        $valid = $request->validate([
            'action' => ['required', 'string', 'in:read,unread,delete'],
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $count = $update->handle($user, (string) $valid['action'], array_values(array_map('strval', (array) $valid['ids'])));

        return response()->json(['message' => match ($valid['action']) {
            'read' => trans_choice(':count notification marked as read.|:count notifications marked as read.', $count),
            'unread' => trans_choice(':count notification marked as unread.|:count notifications marked as unread.', $count),
            default => trans_choice(':count notification deleted.|:count notifications deleted.', $count),
        }]);
    }
}
