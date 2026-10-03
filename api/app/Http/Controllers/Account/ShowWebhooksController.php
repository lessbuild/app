<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Webhooks\WebhookEvents;
use App\Support\Webhooks\WebhookExamples;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/account/webhooks`. */
final class ShowWebhooksController
{
    /**
     * Return the account's webhook endpoints with each one's latest deliveries, the events an endpoint can choose, and
     * how to check a delivery's signature.
     *
     * @param  Account  $account
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account): JsonResponse
    {
        $endpoints = WebhookEndpoint::query()->where('account_id', $account->id)->orderBy('id')->get();
        $deliveries = WebhookDelivery::query()->whereIn('webhook_endpoint_id', $endpoints->modelKeys())->latest()->limit(200)->get()
            ->groupBy('webhook_endpoint_id')->map(fn ($group) => $group->take(15));
        $events = [];
        foreach (WebhookEvents::GROUPS as $group => $meanings) {
            $events[] = ['group' => __($group), 'events' => array_map(fn (string $event, string $meaning): array => ['event' => $event, 'meaning' => __($meaning)], array_keys($meanings), array_values($meanings))];
        }

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'endpoints' => $endpoints->map(fn (WebhookEndpoint $endpoint): array => [
                'id' => $endpoint->id,
                'url' => $endpoint->url,
                'description' => $endpoint->description,
                'events' => array_values((array) $endpoint->events),
                'enabled' => (bool) $endpoint->enabled,
                'lastDeliveredAt' => $endpoint->last_delivered_at?->toIso8601String(),
                'lastError' => $endpoint->last_error,
                'deliveries' => ($deliveries[$endpoint->id] ?? collect())->map(fn (WebhookDelivery $delivery): array => [
                    'id' => $delivery->id,
                    'event' => $delivery->event,
                    'status' => $delivery->status,
                    'responseStatus' => $delivery->response_status,
                    'attempts' => $delivery->attempts,
                    'error' => $delivery->error,
                    'at' => $delivery->created_at?->toIso8601String(),
                ])->values(),
            ])->values(),
            'events' => $events,
            'maxFailures' => WebhookEndpoint::MAX_FAILURES,
            'signatureExample' => WebhookExamples::PHP,
        ]);
    }
}
