<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\RemovePushDevice;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeletePushDeviceController
{
    /**
     * Stop sending notifications to one of the person's devices and return to the notification settings.
     *
     * @param  User  $user
     * @param  int  $device
     * @param  RemovePushDevice  $remove
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, int $device, RemovePushDevice $remove): JsonResponse
    {
        $remove->handle($user, $device);

        return response()->json(['redirect' => route('settings.notifications', [], false).'#'.'push', 'message' => __('That device won’t get notifications any more.')]);
    }
}
