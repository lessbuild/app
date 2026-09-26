<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\AlertDeliveryResult;
use App\Modules\Monitor\Data\Telemetry\AlertDeliveryStatus;
use App\Modules\Monitor\Data\Telemetry\AlertDestinationType;
use App\Modules\Monitor\Notifications\IncidentAlertNotification;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use LengthException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class AlertNotificationTransport
{
    public function __construct(private readonly PublicWebhookTarget $targets) {}

    /** @param array{type: AlertDestinationType, endpoint: ?string, secret: ?string, email: ?string} $target
     * @param  array<string, mixed>  $payload
     */
    public function send(string $id, array $target, array $payload): AlertDeliveryResult
    {
        if ($target['type'] === AlertDestinationType::Email) {
            if (! $this->mailConfigured()) {
                return new AlertDeliveryResult(AlertDeliveryStatus::Failed, 'mail_unconfigured');
            }
            try {
                Notification::route('mail', $target['email'])->notifyNow(new IncidentAlertNotification($id, $payload));

                return new AlertDeliveryResult(AlertDeliveryStatus::Accepted);
            } catch (Throwable) {
                return new AlertDeliveryResult(AlertDeliveryStatus::Uncertain, 'mail_result_unknown');
            }
        }

        try {
            $resolved = $this->targets->resolve($target['endpoint'] ?? '', $target['type']);
        } catch (Throwable) {
            return new AlertDeliveryResult(AlertDeliveryStatus::Retrying, 'dns_unavailable');
        }
        if ($resolved['error'] !== null) {
            return new AlertDeliveryResult(
                $resolved['error'] === 'dns_unavailable' ? AlertDeliveryStatus::Retrying : AlertDeliveryStatus::Failed,
                $resolved['error'],
            );
        }
        if (! extension_loaded('curl')) {
            return new AlertDeliveryResult(AlertDeliveryStatus::Failed, 'curl_unavailable');
        }

        $teams = $target['type'] === AlertDestinationType::Teams;
        $pagerDuty = $target['type'] === AlertDestinationType::PagerDuty;
        $discord = $target['type'] === AlertDestinationType::Discord;
        $webhook = $target['type'] === AlertDestinationType::Webhook;
        $retryable = $webhook || $teams || $pagerDuty;
        $timestamp = (string) now('UTC')->timestamp;
        $body = json_encode(
            $webhook ? ['id' => $id, ...$payload] : ($teams
                ? $this->teamsPayload($id, $payload)
                : ($pagerDuty ? $this->pagerDutyPayload($id, $target['secret'] ?? '', $payload)
                    : ($discord ? $this->discordPayload($id, $payload) : $this->slackPayload($id, $payload)))),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $headers = ['X-Beacon-Delivery' => $id, 'User-Agent' => 'Beacon-Alerts/1.0'];
        if ($webhook) {
            $headers['X-Beacon-Timestamp'] = $timestamp;
            $headers['X-Beacon-Signature'] = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $target['secret'] ?? '');
        }
        $address = str_contains($resolved['address'], ':') ? '['.$resolved['address'].']' : $resolved['address'];
        $stream = Utils::streamFor('');
        $size = 0;
        $sink = FnStream::decorate($stream, ['write' => function (string $chunk) use ($stream, &$size): int {
            $size += strlen($chunk);
            if ($size > 16384) {
                throw new LengthException('Webhook response exceeded its limit.');
            }

            return $stream->write($chunk);
        }]);

        try {
            $response = Http::withHeaders($headers)->withBody($body, 'application/json')
                ->connectTimeout(3)->timeout(10)->withoutRedirecting()
                ->setHandler(new CurlHandler)
                ->withOptions([
                    'proxy' => '', 'verify' => true, 'idn_conversion' => false, 'sink' => $sink,
                    'on_headers' => static function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > 16384) {
                            throw new LengthException('Webhook response exceeded its limit.');
                        }
                    },
                    'curl' => [
                        CURLOPT_RESOLVE => [$resolved['host'].':443:'.$address],
                        CURLOPT_PROXY => '', CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                    ],
                ])->post($target['endpoint'].($discord ? '?wait=true' : ''));

            $status = $response->status();
            $discordMessageId = data_get($response->json(), 'id');
            $accepted = match (true) {
                $webhook, $teams => $response->successful(),
                $pagerDuty => $status === 202 && data_get($response->json(), 'status') === 'success',
                $discord => $status === 200 && is_string($discordMessageId) && $discordMessageId !== '',
                default => $status === 200 && trim($response->body()) === 'ok',
            };
            if ($accepted) {
                return new AlertDeliveryResult(AlertDeliveryStatus::Accepted, httpStatus: $status);
            }
            if ($status === 429 || ($retryable && ($status >= 500 || $status === 408))) {
                $retry = $response->header('Retry-After');

                return new AlertDeliveryResult(AlertDeliveryStatus::Retrying, 'provider_retryable', $status,
                    ctype_digit($retry) ? max(30, min(3600, (int) $retry)) : null);
            }
            if (! $retryable && ($status >= 500 || $status === 408 || $response->successful())) {
                return new AlertDeliveryResult(AlertDeliveryStatus::Uncertain, 'provider_result_unknown', $status);
            }

            return new AlertDeliveryResult(AlertDeliveryStatus::Failed, 'provider_rejected', $status);
        } catch (Throwable) {
            return new AlertDeliveryResult(
                $retryable ? AlertDeliveryStatus::Retrying : AlertDeliveryStatus::Uncertain, 'transport_result_unknown',
            );
        } finally {
            $sink->close();
        }
    }

    public function mailConfigured(): bool
    {
        $mailer = config('monitor.beacon.alerts.mailer');
        $config = is_string($mailer) ? config('mail.mailers.'.$mailer, []) : [];
        if (! is_array($config) || config('mail.driver')) {
            return false;
        }
        if (isset($config['url'])) {
            try {
                $config = array_merge($config, (new ConfigurationUrlParser)->parseConfiguration($config));
                $config['transport'] = $config['driver'] ?? null;
            } catch (Throwable) {
                return false;
            }
        }

        return ($config['transport'] ?? null) === 'smtp'
            && is_numeric($config['timeout'] ?? null) && $config['timeout'] > 0 && $config['timeout'] <= 15;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function slackPayload(string $id, array $payload): array
    {
        $title = config('app.name').': '.ucfirst($payload['event']).' — '.$payload['title'];
        $context = $payload['application'].' / '.$payload['environment'];
        $text = $title."\n".$context."\nDelivery ".$id;
        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'plain_text', 'text' => mb_substr($text, 0, 2900), 'emoji' => false]],
        ];
        if ($payload['url'] !== null) {
            $blocks[] = ['type' => 'actions', 'elements' => [
                ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'View incident'], 'url' => $payload['url']],
            ]];
        }

        return ['text' => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $title), 'blocks' => $blocks, 'unfurl_links' => false, 'unfurl_media' => false];
    }

    /** @param array<string, mixed> $payload */
    private function teamsPayload(string $id, array $payload): array
    {
        $title = config('app.name').': '.ucfirst($payload['event']).' — '.$payload['title'];
        $facts = [
            ['name' => 'Application', 'value' => (string) $payload['application']],
            ['name' => 'Environment', 'value' => (string) $payload['environment']],
            ['name' => 'Delivery', 'value' => $id],
        ];

        return [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => mb_substr($title, 0, 250),
            'themeColor' => $payload['event'] === 'recovered' ? '16A34A' : 'DC2626',
            'title' => mb_substr($title, 0, 250),
            'sections' => [['facts' => $facts, 'markdown' => true]],
            ...($payload['url'] !== null ? ['potentialAction' => [[
                '@type' => 'OpenUri', 'name' => 'View incident', 'targets' => [['os' => 'default', 'uri' => $payload['url']]],
            ]]] : []),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function pagerDutyPayload(string $id, string $routingKey, array $payload): array
    {
        $dedupKey = 'beacon-incident-'.($payload['incident_id'] ?? $id);

        return [
            'routing_key' => $routingKey,
            'event_action' => $payload['event'] === 'recovered' ? 'resolve' : 'trigger',
            'dedup_key' => $dedupKey,
            'payload' => [
                'summary' => mb_substr(config('app.name').': '.ucfirst($payload['event']).' — '.$payload['title'], 0, 1024),
                'source' => (string) $payload['application'].' / '.$payload['environment'],
                'severity' => $payload['event'] === 'recovered' ? 'info' : 'critical',
                'custom_details' => ['delivery_id' => $id],
            ],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function discordPayload(string $id, array $payload): array
    {
        $title = config('app.name').': '.ucfirst($payload['event']).' — '.$payload['title'];

        return [
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => mb_substr($title, 0, 256),
                'color' => match ($payload['event']) {
                    'recovered' => 0x16A34A,
                    'opened', 'escalated' => 0xDC2626,
                    default => 0x64748B,
                },
                'fields' => [
                    ['name' => 'Application', 'value' => mb_substr((string) $payload['application'], 0, 1024), 'inline' => true],
                    ['name' => 'Environment', 'value' => mb_substr((string) $payload['environment'], 0, 1024), 'inline' => true],
                    ['name' => 'Delivery', 'value' => mb_substr($id, 0, 1024)],
                ],
                ...($payload['url'] !== null ? ['url' => $payload['url']] : []),
            ]],
        ];
    }
}
