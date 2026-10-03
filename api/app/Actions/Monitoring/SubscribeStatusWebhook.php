<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\AlertDestinationType;
use App\Jobs\Monitoring\ConfirmStatusWebhookSubscription;
use App\Models\StatusPage;
use App\Models\StatusWebhookSubscription;
use App\Services\Monitoring\PublicWebhookTarget;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SubscribeStatusWebhook
{
    /**
     * Create a new SubscribeStatusWebhook instance.
     *
     * @param  PublicWebhookTarget  $targets  Checks the address is a Slack webhook, or a public HTTPS one.
     */
    public function __construct(private readonly PublicWebhookTarget $targets) {}

    /**
     * Subscribe a Slack incoming webhook or a signed webhook to a published page's updates. A confirmation is posted
     * to it in the background, and the subscription is active once that's accepted. Subscribing the same address
     * again starts it over. Returns the subscription and, for webhooks, the signing secret (shown once).
     *
     * @param  StatusPage  $page
     * @param  string  $type  slack or webhook
     * @param  string  $url
     * @return array{0: StatusWebhookSubscription, 1: string|null}
     */
    public function handle(StatusPage $page, string $type, string $url): array
    {
        $url = trim($url);
        $kind = $type === 'slack' ? AlertDestinationType::Slack : AlertDestinationType::Webhook;
        if ($this->targets->host($url, $kind) === null) {
            throw ValidationException::withMessages(['url' => $type === 'slack'
                ? __('Paste a Slack incoming webhook address (https://hooks.slack.com/services/…).')
                : __('Use a public HTTPS address on port 443.')]);
        }
        $secret = $type === 'slack' ? null : Str::random(48);
        $subscription = StatusWebhookSubscription::query()->where('status_page_id', $page->id)->where('endpoint_hash', hash('sha256', $url))->first()
            ?? (new StatusWebhookSubscription)->forceFill(['status_page_id' => $page->id, 'endpoint_hash' => hash('sha256', $url)]);
        $subscription->forceFill([
            'type' => $type === 'slack' ? 'slack' : 'webhook', 'endpoint_url' => $url, 'signing_secret' => $secret,
            'unsubscribe_token' => Str::random(64), 'verified_at' => null, 'failure_count' => 0,
        ])->save();
        ConfirmStatusWebhookSubscription::dispatch($subscription->id);

        return [$subscription, $secret];
    }
}
