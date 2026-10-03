<?php

declare(strict_types=1);

namespace App\Jobs\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one webhook delivery, retrying with growing waits (30 seconds up to two hours) until it's accepted or has
 * been tried six times. An endpoint whose deliveries fail 20 times in a row is paused.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The waits before each retry, in seconds.
     *
     * @var list<int>
     */
    private const BACKOFF = [30, 120, 600, 1800, 7200];

    /**
     * Create a new DeliverWebhook instance.
     *
     * @param  string  $deliveryId  The delivery to send.
     */
    public function __construct(public readonly string $deliveryId) {}

    /**
     * Send the delivery and record the outcome; a 2xx answer counts as delivered.
     *
     * @param  WebhookSender  $sender
     * @return void
     */
    public function handle(WebhookSender $sender): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);
        if ($delivery === null || $delivery->status !== 'pending') {
            return;
        }
        $endpoint = $delivery->endpoint;
        $status = null;
        $error = null;
        try {
            $response = $sender->send($endpoint, $delivery);
            $status = $response->status();
            $error = $response->successful() ? null : "HTTP {$status}";
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
        $attempts = $delivery->attempts + 1;
        if ($error === null) {
            $delivery->forceFill(['status' => 'delivered', 'attempts' => $attempts, 'response_status' => $status, 'error' => null, 'delivered_at' => now()])->save();
            $endpoint->forceFill(['failure_count' => 0, 'last_error' => null, 'last_delivered_at' => now()])->save();

            return;
        }
        $final = $attempts >= WebhookDelivery::MAX_ATTEMPTS;
        $delivery->forceFill(['status' => $final ? 'failed' : 'pending', 'attempts' => $attempts, 'response_status' => $status, 'error' => Str::limit($error, 290)])->save();
        if ($final) {
            $failures = $endpoint->failure_count + 1;
            $endpoint->forceFill(['failure_count' => $failures, 'last_error' => Str::limit($error, 290), 'enabled' => $endpoint->enabled && $failures < WebhookEndpoint::MAX_FAILURES])->save();

            return;
        }
        self::dispatch($delivery->id)->delay(now()->addSeconds(self::BACKOFF[$attempts - 1] ?? 7200));
    }
}
