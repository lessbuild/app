<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\Account;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\Webhooks;
use Illuminate\Support\Facades\Gate;

final class SendWebhook
{
    /**
     * Create a new SendWebhook instance.
     *
     * @param  Webhooks  $webhooks  Queues deliveries.
     */
    public function __construct(private readonly Webhooks $webhooks) {}

    /**
     * Send a `ping` event to try an endpoint, or send an earlier delivery again (same ID, so receivers can tell).
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  WebhookEndpoint  $endpoint
     * @param  WebhookDelivery|null  $delivery  the one to send again, or null for a ping
     * @return WebhookDelivery
     */
    public function handle(User $actor, Account $account, WebhookEndpoint $endpoint, ?WebhookDelivery $delivery = null): WebhookDelivery
    {
        Gate::forUser($actor)->authorize('update', $account);
        abort_if($endpoint->account_id !== $account->id || ($delivery !== null && $delivery->webhook_endpoint_id !== $endpoint->id), 404);
        if ($delivery === null) {
            return $this->webhooks->queue($endpoint, 'ping', ['message' => __('A test from :app.', ['app' => config('app.name')]), 'endpoint_id' => $endpoint->id]);
        }
        $delivery->forceFill(['status' => 'pending', 'attempts' => 0, 'error' => null, 'response_status' => null, 'delivered_at' => null])->save();
        DeliverWebhook::dispatch($delivery->id);

        return $delivery;
    }
}
