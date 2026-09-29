<?php

declare(strict_types=1);

namespace App\Jobs\Monitoring;

use App\Models\StatusWebhookSubscription;
use App\Services\Monitoring\StatusWebhookSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Posts the "you're subscribed" message to a new Slack or webhook subscriber; accepted means active, else removed. */
final class ConfirmStatusWebhookSubscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new ConfirmStatusWebhookSubscription instance.
     *
     * @param  int  $subscriptionId  The subscription to confirm.
     */
    public function __construct(public readonly int $subscriptionId) {}

    /**
     * Post the confirmation; activate the subscription when it's accepted, remove it when it isn't.
     *
     * @param  StatusWebhookSender  $sender
     * @return void
     */
    public function handle(StatusWebhookSender $sender): void
    {
        $subscription = StatusWebhookSubscription::query()->with('statusPage')->whereNull('verified_at')->find($this->subscriptionId);
        if ($subscription === null) {
            return;
        }
        if ($sender->confirm($subscription)) {
            $subscription->forceFill(['verified_at' => now()])->save();
        } else {
            $subscription->delete();
        }
    }
}
