<?php

namespace App\Jobs;

use App\Models\AlertDestination;
use App\Notifications\AlertEmailNotification;
use App\Support\PublicIpAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class DeliverAlertWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /** @param array<string, mixed> $payload */
    public function __construct(public int $destinationId, public array $payload) {}

    public function handle(): void
    {
        $destination = AlertDestination::query()->find($this->destinationId);
        if (! $destination?->is_active || ! in_array($this->payload['event'] ?? null, $destination->events ?? [], true)) {
            return;
        }
        if ($destination->type === 'email') {
            Notification::route('mail', $destination->endpoint)->notify(new AlertEmailNotification($this->payload));
            $destination->update(['last_delivered_at' => now(), 'last_failed_at' => null, 'last_error' => null]);

            return;
        }

        $endpoint = $destination->type === 'pagerduty'
            ? 'https://events.pagerduty.com/v2/enqueue'
            : $destination->endpoint;
        $this->assertPublicEndpoint($endpoint);
        $body = match ($destination->type) {
            'slack' => ['text' => "*{$this->payload['title']}*\n{$this->payload['message']}"],
            'discord' => ['content' => "**{$this->payload['title']}**\n{$this->payload['message']}", 'allowed_mentions' => ['parse' => []]],
            'teams' => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'https://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.2',
                        'body' => [
                            ['type' => 'TextBlock', 'weight' => 'Bolder', 'text' => (string) $this->payload['title']],
                            ['type' => 'TextBlock', 'wrap' => true, 'text' => (string) $this->payload['message']],
                        ],
                    ],
                ]],
            ],
            'pagerduty' => [
                'routing_key' => $destination->endpoint,
                'event_action' => ($this->payload['event'] ?? null) === 'recovery' ? 'resolve' : 'trigger',
                'dedup_key' => ($this->payload['category'] ?? 'event').'-'.($this->payload['resource_id'] ?? 0),
                'payload' => [
                    'summary' => (string) ($this->payload['title'] ?? 'BuildPusher alert'),
                    'source' => 'BuildPusher',
                    'severity' => 'error',
                    'custom_details' => ['message' => (string) ($this->payload['message'] ?? '')],
                ],
            ],
            default => $this->payload,
        };
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $json, $destination->signing_secret);
        $response = Http::withoutRedirecting()
            ->timeout(10)
            ->connectTimeout(5)
            ->withHeaders([
                'User-Agent' => 'BuildPusher-Alerts/1.0',
                'X-BuildPusher-Signature' => 'sha256='.$signature,
                'X-BuildPusher-Event' => (string) $this->payload['event'],
            ])
            ->withBody($json, 'application/json')
            ->send('POST', $endpoint);
        if (! $response->successful()) {
            throw new RuntimeException("Alert destination returned HTTP {$response->status()}.");
        }
        $destination->update(['last_delivered_at' => now(), 'last_failed_at' => null, 'last_error' => null]);
    }

    public function failed(\Throwable $exception): void
    {
        AlertDestination::query()->whereKey($this->destinationId)->update([
            'last_failed_at' => now(),
            'last_error' => str($exception->getMessage())->limit(1000),
        ]);
    }

    private function assertPublicEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        $host = $parts['host'] ?? null;
        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($host) || $host === '') {
            throw new RuntimeException('Alert destinations must use HTTPS.');
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === [] || collect($addresses)->contains(fn (string $ip): bool => ! PublicIpAddress::isValid($ip))) {
            throw new RuntimeException('Alert destination does not resolve to a public address.');
        }
    }
}
