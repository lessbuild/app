<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\UpdateNotifications;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `DELETE /api/app/notifications/read`. */
final class ClearReadNotificationsController
{
    /**
     * Delete every notification the person has read.
     *
     * @param  User  $user
     * @param  UpdateNotifications  $update
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, UpdateNotifications $update): JsonResponse
    {
        $count = $update->handle($user, 'clear-read');

        return response()->json(['message' => trans_choice(':count read notification deleted.|:count read notifications deleted.', $count)]);
    }
}
