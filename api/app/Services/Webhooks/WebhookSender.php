<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\AlertDestinationType;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Monitoring\PublicWebhookTarget;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts a delivery to its endpoint as signed JSON: public HTTPS only, connected to at the address checked, no
 * redirects. The signature is `v1=` HMAC-SHA256 of "{timestamp}.{body}" with the endpoint's secret.
 */
final class WebhookSender
{
    /**
     * Create a new WebhookSender instance.
     *
     * @param  PublicWebhookTarget  $targets  Checks and resolves the address.
     */
    public function __construct(private readonly PublicWebhookTarget $targets) {}

    /**
     * Send the delivery and return the response; throws when the address isn't allowed.
     *
     * @param  WebhookEndpoint  $endpoint
     * @param  WebhookDelivery  $delivery
     * @return Response
     */
    public function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery): Response
    {
        $target = $this->targets->resolve($endpoint->url, AlertDestinationType::Webhook);
        if ($target['error'] !== null || $target['host'] === null || $target['address'] === null) {
            throw new RuntimeException('The address isn’t a public HTTPS address ('.($target['error'] ?? 'invalid').').');
        }
        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now('UTC')->timestamp;
        $address = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];

        return Http::withHeaders([
            'User-Agent' => config('app.name').'-Webhooks/1.0',
            'X-BuildPusher-Event' => $delivery->event,
            'X-BuildPusher-Delivery' => $delivery->id,
            'X-BuildPusher-Timestamp' => $timestamp,
            'X-BuildPusher-Signature' => self::signature($timestamp, $body, $endpoint->signing_secret),
        ])->withBody($body, 'application/json')->connectTimeout(3)->timeout(10)->withoutRedirecting()
            ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:443:{$address}"]]])->post($endpoint->url);
    }

    /**
     * Sign a body the way receivers check it.
     *
     * @param  string  $timestamp
     * @param  string  $body
     * @param  string  $secret
     * @return string
     */
    public static function signature(string $timestamp, string $body, string $secret): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
