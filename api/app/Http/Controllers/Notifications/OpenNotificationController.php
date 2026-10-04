<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\OpenNotification;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST /api/app/notifications/{notification}/open`. */
final class OpenNotificationController
{
    /**
     * Mark a notification read and say where it leads.
     *
     * @param  User  $user
     * @param  string  $notification
     * @param  OpenNotification  $open
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, string $notification, OpenNotification $open): JsonResponse
    {
        $target = $open->handle($user, $notification);
        $parts = parse_url($target);

        return response()->json(['redirect' => ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '')]);
    }
}
