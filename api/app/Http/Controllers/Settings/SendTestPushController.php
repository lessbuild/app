<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\SendTestPush;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class SendTestPushController
{
    /**
     * Send a test notification to the person's devices and return to the notification settings.
     *
     * @param  User  $user
     * @param  SendTestPush  $send
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, SendTestPush $send): JsonResponse
    {
        $sent = $send->handle($user);

        return response()->json(['redirect' => route('settings.notifications', [], false).'#'.'push', 'message' => trans_choice('Sent to :count device.|Sent to :count devices.', $sent, ['count' => $sent])]);
    }
}
