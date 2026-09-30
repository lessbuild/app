<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\RemovePushDevice;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeletePushDeviceController
{
    /**
     * Stop sending notifications to one of the person's devices and return to the notification settings.
     *
     * @param  User  $user
     * @param  int  $device
     * @param  RemovePushDevice  $remove
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, int $device, RemovePushDevice $remove): RedirectResponse
    {
        $remove->handle($user, $device);

        return to_route('settings.notifications')->withFragment('push')->with('status', __('That device won’t get notifications any more.'));
    }
}
