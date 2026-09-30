<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\Monitoring\AlertDeliveryResult;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationType;
use App\Models\PushSubscription;
use App\Notifications\IncidentAlertNotification;
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
    /**
     * Create a new AlertNotificationTransport instance.
     *
     * Delivers alerts to their destinations.
     *
     * @param  PublicWebhookTarget  $targets  Checks and resolves webhook endpoints.
     * @param  TwilioAlerts  $twilio  Sends text messages and phone calls.
     * @param  WebPush  $webPush  Sends push notifications.
     */
    public function __construct(private readonly PublicWebhookTarget $targets, private readonly TwilioAlerts $twilio, private readonly WebPush $webPush) {}

    /**
     * Send one alert. Email goes through the monitoring mailer. Webhook-style destinations are posted to the address
     * checked when resolving (pinned, so DNS can't change underneath), over HTTPS only, without redirects or proxies,
     * reading at most 16 KiB of response; generic webhooks are signed. Each provider's answer is judged by its own
     * success rule, and failures are sorted into retryable, uncertain and rejected.
     *
     * @param  string  $id
     * @param  array{type: AlertDestinationType, endpoint: ?string, secret: ?string, email: ?string, user?: ?string}  $target
     * @param  array<string, mixed>  $payload
     * @return AlertDeliveryResult
     */
    public function send(string $id, array $target, array $payload): AlertDeliveryResult
    {
        if ($target['type'] === AlertDestinationType::Push) {
            return $this->push($target['user'] ?? null, $payload);
        }
        if ($target['type']->isPhone()) {
            return $this->twilio->send($target['type'], substr((string) $target['endpoint'], 4), $this->spoken($payload));
        }
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
        $address = str_contains((string) $resolved['address'], ':') ? '['.$resolved['address'].']' : $resolved['address'];
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

    /**
     * Determine whether the monitoring mailer is SMTP with a timeout of at most 15 seconds, so a slow mail server
     * can't hold a worker.
     *
     * @return bool
     */
    public function mailConfigured(): bool
    {
        $mailer = config('monitoring.alerts.mailer');
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

    /**
     * Format the alert as a Slack message: plain text (so titles can't inject formatting) and a button to the
     * incident.
     *
     * @param  string  $id
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function slackPayload(string $id, array $payload): array
    {
        $title = $this->heading($payload);
        $context = $payload['application'].' / '.$payload['environment'];
        $text = $title."\n".$context.(is_string($payload['notes'] ?? null) ? "\n\n".$payload['notes'] : '')."\nDelivery ".$id;
        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'plain_text', 'text' => mb_substr($text, 0, 2900), 'emoji' => false]],
        ];
        if ($payload['url'] !== null) {
            $blocks[] = ['type' => 'actions', 'elements' => [
                ...array_map(fn (array $action): array => array_filter(['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $action['label']], 'url' => $action['url'], 'style' => $action['style'] ?? null]), $this->actions($payload)),
                ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $this->linkLabel($payload)], 'url' => $payload['url']],
            ]];
        }

        return ['text' => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $title), 'blocks' => $blocks, 'unfurl_links' => false, 'unfurl_media' => false];
    }

    /**
     * Format the alert as a Teams message card, green for recoveries and red otherwise.
     *
     * @param  string  $id
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function teamsPayload(string $id, array $payload): array
    {
        $title = $this->heading($payload);
        $facts = [
            ['name' => 'Application', 'value' => (string) $payload['application']],
            ['name' => 'Environment', 'value' => (string) $payload['environment']],
            ['name' => 'Delivery', 'value' => $id],
        ];

        return [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => mb_substr($title, 0, 250),
            'themeColor' => match ($payload['event']) {
                'recovered', 'deploy_succeeded' => '16A34A', 'deploy_approval' => 'D97706', default => 'DC2626'
            },
            'title' => mb_substr($title, 0, 250),
            'sections' => [['facts' => $facts, 'markdown' => true]],
            ...($payload['url'] !== null ? ['potentialAction' => [
                ...array_map(fn (array $action): array => ['@type' => 'OpenUri', 'name' => $action['label'], 'targets' => [['os' => 'default', 'uri' => $action['url']]]], $this->actions($payload)),
                ['@type' => 'OpenUri', 'name' => $this->linkLabel($payload), 'targets' => [['os' => 'default', 'uri' => $payload['url']]]],
            ]] : []),
        ];
    }

    /**
     * Format the alert as a PagerDuty event: triggering, or resolving on recovery, deduplicated per incident.
     *
     * @param  string  $id
     * @param  string  $routingKey
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function pagerDutyPayload(string $id, string $routingKey, array $payload): array
    {
        $dedupKey = 'beacon-incident-'.($payload['incident_id'] ?? $id);

        return [
            'routing_key' => $routingKey,
            'event_action' => $payload['event'] === 'recovered' ? 'resolve' : 'trigger',
            'dedup_key' => $dedupKey,
            'payload' => [
                'summary' => mb_substr($this->heading($payload), 0, 1024),
                'source' => (string) $payload['application'].' / '.$payload['environment'],
                'severity' => $payload['event'] === 'recovered' ? 'info' : 'critical',
                'custom_details' => ['delivery_id' => $id],
            ],
        ];
    }

    /**
     * Format the alert as a Discord embed with mentions disabled, so titles can't ping anyone.
     *
     * @param  string  $id
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function discordPayload(string $id, array $payload): array
    {
        $title = $this->heading($payload);

        return [
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => mb_substr($title, 0, 256),
                'description' => is_string($payload['notes'] ?? null) ? mb_substr($payload['notes'], 0, 4000) : null,
                'color' => match ($payload['event']) {
                    'recovered', 'deploy_succeeded' => 0x16A34A,
                    'opened', 'escalated', 'deploy_failed' => 0xDC2626,
                    'deploy_approval' => 0xD97706,
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

    /**
     * Build the one-line heading, e.g. "BuildPusher: Opened — API down" or "BuildPusher: Deploy failed — Deploy #12…".
     *
     * @param  array<string, mixed>  $payload
     * @return string
     */
    private function heading(array $payload): string
    {
        $label = is_string($payload['event_label'] ?? null) ? $payload['event_label'] : ucfirst((string) $payload['event']);

        return config('app.name').': '.$label.' — '.$payload['title'];
    }

    /**
     * Get the extra buttons an alert offers, such as Approve and Reject on a deploy waiting for approval.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, url: string, style?: string}>
     */
    private function actions(array $payload): array
    {
        $actions = [];
        foreach (is_array($payload['actions'] ?? null) ? $payload['actions'] : [] as $action) {
            if (is_array($action) && is_string($action['label'] ?? null) && is_string($action['url'] ?? null)) {
                $button = ['label' => $action['label'], 'url' => $action['url']];
                if (is_string($action['style'] ?? null)) {
                    $button['style'] = $action['style'];
                }
                $actions[] = $button;
            }
        }

        return $actions;
    }

    /**
     * Get the text for the link button: "View incident" unless the payload says otherwise (deploys say "View deploy").
     *
     * @param  array<string, mixed>  $payload
     * @return string
     */
    private function linkLabel(array $payload): string
    {
        return is_string($payload['url_label'] ?? null) ? $payload['url_label'] : 'View incident';
    }

    /**
     * Put an alert into a short sentence for a text message or phone call, e.g. "BuildPusher: Opened — API down.
     * Shop, production."
     *
     * @param  array<string, mixed>  $payload
     * @return string
     */
    private function spoken(array $payload): string
    {
        $where = implode(', ', array_filter([
            is_string($payload['project'] ?? null) ? $payload['project'] : null,
            is_string($payload['environment'] ?? null) ? $payload['environment'] : null,
        ]));

        return $this->heading($payload).'.'.($where !== '' ? ' '.$where.'.' : '');
    }

    /**
     * Push the alert to each of the person's devices, forgetting devices that unsubscribed. Accepted when at least
     * one device took it, failed when they have no devices left, and retried when the push services didn't answer.
     *
     * @param  string|null  $userId
     * @param  array<string, mixed>  $payload
     * @return AlertDeliveryResult
     */
    private function push(?string $userId, array $payload): AlertDeliveryResult
    {
        $devices = $userId === null ? collect() : PushSubscription::query()->where('user_id', $userId)->get();
        if ($devices->isEmpty()) {
            return new AlertDeliveryResult(AlertDeliveryStatus::Failed, 'no_push_devices');
        }
        $where = implode(' / ', array_filter([is_string($payload['project'] ?? null) ? $payload['project'] : null, is_string($payload['environment'] ?? null) ? $payload['environment'] : null]));
        $message = ['title' => mb_substr($this->heading($payload), 0, 120), 'body' => $where, 'url' => is_string($payload['url'] ?? null) ? $payload['url'] : route('dashboard'), 'tag' => 'incident-'.($payload['incident_id'] ?? 'alert'), 'urgent' => ($payload['event'] ?? null) !== 'recovered'];
        $sent = 0;
        $remaining = $devices->count();
        foreach ($devices as $device) {
            $result = $this->webPush->send($device, $message);
            if ($result === 'gone') {
                $device->delete();
                $remaining--;
            } elseif ($result === 'sent') {
                $device->forceFill(['last_used_at' => now()])->save();
                $sent++;
            }
        }

        return match (true) {
            $sent > 0 => new AlertDeliveryResult(AlertDeliveryStatus::Accepted),
            $remaining === 0 => new AlertDeliveryResult(AlertDeliveryStatus::Failed, 'no_push_devices'),
            default => new AlertDeliveryResult(AlertDeliveryStatus::Retrying, 'push_failed'),
        };
    }
}
