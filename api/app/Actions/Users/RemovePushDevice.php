<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\PushSubscription;
use App\Models\User;

final class RemovePushDevice
{
    /**
     * Stop sending notifications to one of the person's devices.
     *
     * @param  User  $user
     * @param  int  $subscriptionId
     * @return void
     */
    public function handle(User $user, int $subscriptionId): void
    {
        PushSubscription::query()->where('user_id', $user->id)->whereKey($subscriptionId)->delete();
    }
}
