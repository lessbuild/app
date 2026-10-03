<?php

declare(strict_types=1);

namespace App\Jobs\Monitoring;

use App\Models\StatusUpdate;
use App\Models\StatusWebhookSubscription;
use App\Services\Monitoring\StatusWebhookSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Posts a status update to the page's active Slack and webhook subscribers. */
final class SendStatusUpdateToWebhooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new SendStatusUpdateToWebhooks instance.
     *
     * @param  int  $updateId  The update to post, read again so it's current.
     */
    public function __construct(public readonly int $updateId) {}

    /**
     * Post the update to each active subscriber. A failure counts against the subscriber; after five in a row it's
     * removed. A success resets the count.
     *
     * @param  StatusWebhookSender  $sender
     * @return void
     */
    public function handle(StatusWebhookSender $sender): void
    {
        $update = StatusUpdate::query()->with('statusPage')->find($this->updateId);
        if ($update === null || ! $update->statusPage->published) {
            return;
        }
        StatusWebhookSubscription::query()->where('status_page_id', $update->status_page_id)->whereNotNull('verified_at')->with('statusPage')
            ->lazyById(200)->each(function (StatusWebhookSubscription $subscription) use ($sender, $update): void {
                if ($sender->update($subscription, $update)) {
                    $subscription->forceFill(['failure_count' => 0])->save();
                } elseif ($subscription->failure_count + 1 >= StatusWebhookSubscription::MAX_FAILURES) {
                    $subscription->delete();
                } else {
                    $subscription->forceFill(['failure_count' => $subscription->failure_count + 1])->save();
                }
            });
    }
}
