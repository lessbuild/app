<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Enums\AlertDestinationType;
use App\Models\StatusUpdate;
use App\Models\StatusWebhookSubscription;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posts status page messages to Slack channels and signed webhooks, only on public HTTPS addresses and connecting to
 * the address that was checked.
 */
final class StatusWebhookSender
{
    /**
     * Create a new StatusWebhookSender instance.
     *
     * @param  PublicWebhookTarget  $targets  Checks and resolves the endpoint.
     */
    public function __construct(private readonly PublicWebhookTarget $targets) {}

    /**
     * Post the confirmation that a subscription is set up. Returns whether it was accepted.
     *
     * @param  StatusWebhookSubscription  $subscription
     * @return bool
     */
    public function confirm(StatusWebhookSubscription $subscription): bool
    {
        $page = $subscription->statusPage;
        $text = __('Subscribed to :page status updates. Unsubscribe: :url', ['page' => $page->name, 'url' => $this->unsubscribeUrl($subscription)]);

        return $this->post($subscription, ['event' => 'subscription.confirmed', 'page' => $page->name, 'page_url' => $page->publicUrl(), 'unsubscribe_url' => $this->unsubscribeUrl($subscription)], $text);
    }

    /**
     * Post a status update. Returns whether it was accepted.
     *
     * @param  StatusWebhookSubscription  $subscription
     * @param  StatusUpdate  $update
     * @return bool
     */
    public function update(StatusWebhookSubscription $subscription, StatusUpdate $update): bool
    {
        $page = $subscription->statusPage;
        $status = $update->statusLabel();
        $text = "*{$page->name}: {$update->title}* ({$status})\n{$update->message}\n<{$page->publicUrl()}|".__('View status page').'>';

        return $this->post($subscription, [
            'event' => 'status_update', 'page' => $page->name, 'page_url' => $page->publicUrl(),
            'update' => ['id' => $update->id, 'kind' => $update->kind, 'status' => $update->status, 'severity' => $update->severity, 'title' => $update->title, 'message' => $update->message,
                'starts_at' => $update->starts_at->toIso8601String(), 'resolved_at' => $update->resolved_at?->toIso8601String()],
            'unsubscribe_url' => $this->unsubscribeUrl($subscription),
        ], $text);
    }

    /**
     * Get the subscription's unsubscribe link.
     *
     * @param  StatusWebhookSubscription  $subscription
     * @return string
     */
    public function unsubscribeUrl(StatusWebhookSubscription $subscription): string
    {
        return route('status.webhooks.unsubscribe', [$subscription->id, $subscription->unsubscribe_token]);
    }

    /**
     * Post a message: Slack gets the text; a webhook gets the JSON, signed with its secret like alert webhooks.
     *
     * @param  StatusWebhookSubscription  $subscription
     * @param  array<string, mixed>  $json
     * @param  string  $text
     * @return bool
     */
    private function post(StatusWebhookSubscription $subscription, array $json, string $text): bool
    {
        $slack = $subscription->type === 'slack';
        $target = $this->targets->resolve($subscription->endpoint_url, $slack ? AlertDestinationType::Slack : AlertDestinationType::Webhook);
        if ($target['error'] !== null || $target['host'] === null || $target['address'] === null) {
            return false;
        }
        $body = json_encode($slack ? ['text' => $text] : $json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now('UTC')->timestamp;
        $headers = ['User-Agent' => config('app.name').'-Status/1.0'];
        if (! $slack) {
            $headers['X-BuildPusher-Timestamp'] = $timestamp;
            $headers['X-BuildPusher-Signature'] = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $subscription->signing_secret);
        }
        $address = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];
        try {
            $response = Http::withHeaders($headers)->withBody($body, 'application/json')->connectTimeout(3)->timeout(10)->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:443:{$address}"]]])
                ->post($subscription->endpoint_url);
        } catch (Throwable) {
            return false;
        }

        return $response->successful();
    }
}
