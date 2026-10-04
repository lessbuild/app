<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\MarkAllNotificationsRead;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST /api/app/notifications/read`. */
final class MarkAllNotificationsReadController
{
    /**
     * Mark every notification read.
     *
     * @param  User  $user
     * @param  MarkAllNotificationsRead  $markAll
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, MarkAllNotificationsRead $markAll): JsonResponse
    {
        $markAll->handle($user);

        return response()->json(['message' => __('All caught up.')]);
    }
}
