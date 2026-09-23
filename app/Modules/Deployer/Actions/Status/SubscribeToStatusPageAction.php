<?php

namespace App\Modules\Deployer\Actions\Status;

use App\Modules\Deployer\Models\StatusPage;
use App\Modules\Deployer\Models\StatusSubscription;
use App\Modules\Deployer\Notifications\ConfirmStatusSubscriptionNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class SubscribeToStatusPageAction
{
    /**
     * Replace or create a pending email subscription and send its confirmation notification.
     *
     * @param  StatusPage  $page  Published status page receiving the subscription.
     * @param  string  $email  Validated normalized subscriber address.
     * @return StatusSubscription The pending subscription used by the notification.
     */
    public function handle(StatusPage $page, string $email): StatusSubscription
    {
        $token = Str::random(64);
        $subscription = $page->subscriptions()->updateOrCreate(
            ['email_hash' => hash('sha256', $email)],
            [
                'email' => $email,
                'verification_token_hash' => hash('sha256', $token),
                'unsubscribe_token' => Str::random(64),
                'verified_at' => null,
            ],
        );
        Notification::route('mail', $email)->notify(new ConfirmStatusSubscriptionNotification($subscription, $token));

        return $subscription;
    }
}
