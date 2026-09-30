<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Monitoring\WebPush;

final class SendTestPush
{
    /**
     * Create a new SendTestPush instance.
     *
     * @param  WebPush  $push  Sends the notification.
     */
    public function __construct(private readonly WebPush $push) {}

    /**
     * Send a test notification to each of the person's devices, forgetting any that have unsubscribed. Returns how
     * many received it.
     *
     * @param  User  $user
     * @return int
     */
    public function handle(User $user): int
    {
        $sent = 0;
        foreach (PushSubscription::query()->where('user_id', $user->id)->get() as $subscription) {
            $result = $this->push->send($subscription, ['title' => __('BuildPusher test'), 'body' => __('Alerts will reach this device.'), 'url' => route('settings.notifications'), 'tag' => 'test']);
            if ($result === 'gone') {
                $subscription->delete();
            } elseif ($result === 'sent') {
                $subscription->forceFill(['last_used_at' => now()])->save();
                $sent++;
            }
        }

        return $sent;
    }
}
