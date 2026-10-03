<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/** Queues an account's events for each enabled webhook endpoint that wants them. */
final class Webhooks
{
    /**
     * Queue an event for the account's endpoints. Cheap when the account has none.
     *
     * @param  string  $accountId
     * @param  string  $event  one of WebhookEvents
     * @param  array<string, mixed>  $data
     * @return int endpoints it was queued for
     */
    public function dispatch(string $accountId, string $event, array $data): int
    {
        $endpoints = WebhookEndpoint::query()->where('account_id', $accountId)->where('enabled', true)->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->wants($event));
        foreach ($endpoints as $endpoint) {
            $this->queue($endpoint, $event, $data);
        }

        return $endpoints->count();
    }

    /**
     * Record and queue one delivery.
     *
     * @param  WebhookEndpoint  $endpoint
     * @param  string  $event
     * @param  array<string, mixed>  $data
     * @return WebhookDelivery
     */
    public function queue(WebhookEndpoint $endpoint, string $event, array $data): WebhookDelivery
    {
        $delivery = new WebhookDelivery;
        $id = (string) Str::ulid();
        $delivery->forceFill([
            'id' => $id, 'webhook_endpoint_id' => $endpoint->id, 'event' => $event, 'status' => 'pending',
            'payload' => ['id' => $id, 'event' => $event, 'created_at' => now()->utc()->toIso8601String(), 'account_id' => $endpoint->account_id, 'data' => $data],
        ])->save();
        DeliverWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }
}
