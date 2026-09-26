<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Support\BoundedWebhookResponseSink;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Http;
use LengthException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class AlertWebhookTransport
{
    public const MAX_RESPONSE_BYTES = 16384;

    public const MAX_RESPONSE_HEADER_BYTES = 16384;

    public function __construct(private readonly AlertWebhookTargetResolver $targets) {}

    /**
     * Send the existing product-owned event representation with the destination's current secret and a DNS-pinned target.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, error_code: string|null, http_status: int|null, retry_after: int|null}
     */
    public function send(AlertDestination $destination, array $payload): array
    {
        $endpoint = $destination->type === 'pagerduty'
            ? 'https://events.pagerduty.com/v2/enqueue'
            : (string) $destination->endpoint;
        $target = $this->targets->resolve($endpoint);
        if ($target['error'] !== null) {
            $retryable = $target['error'] === 'dns_unavailable';

            return $this->result($retryable ? 'retrying' : 'failed', $target['error']);
        }
        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            return $this->result('failed', 'curl_unavailable');
        }

        try {
            $body = $this->body($destination, $payload);
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            return $this->result('failed', 'payload_invalid');
        }
        $signature = hash_hmac('sha256', $json, (string) $destination->signing_secret);
        try {
            $sink = BoundedWebhookResponseSink::open(self::MAX_RESPONSE_BYTES);
        } catch (Throwable) {
            return $this->result('failed', 'response_sink_unavailable');
        }
        if (! is_resource($sink)) {
            return $this->result('failed', 'response_sink_unavailable');
        }
        $curl = [
            CURLOPT_PROXY => '',
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ];
        if (! filter_var($target['host'], FILTER_VALIDATE_IP)) {
            $address = str_contains((string) $target['address'], ':')
                ? '['.$target['address'].']'
                : $target['address'];
            $curl[CURLOPT_RESOLVE] = [$target['host'].':'.$target['port'].':'.$address];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'BuildPusher-Alerts/1.0',
                'X-BuildPusher-Signature' => 'sha256='.$signature,
                'X-BuildPusher-Event' => (string) ($payload['event'] ?? 'unknown'),
            ])
                ->withBody($json, 'application/json')
                ->connectTimeout(5)
                ->timeout(10)
                ->withoutRedirecting()
                ->setHandler(new CurlHandler)
                ->withOptions([
                    'proxy' => '',
                    'verify' => true,
                    'idn_conversion' => false,
                    'sink' => $sink,
                    'on_headers' => static function (ResponseInterface $response): void {
                        if (self::responseHeadersExceeded($response)) {
                            throw new LengthException('Response exceeded configured size limit.');
                        }
                    },
                    'curl' => $curl,
                ])
                ->send('POST', $endpoint);

            $status = $response->status();
            if (self::responseHeadersExceeded($response->toPsrResponse())
                || BoundedWebhookResponseSink::exceeded($sink)
                || strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
                return $this->result('uncertain', 'response_too_large', $status);
            }
            if ($response->successful()) {
                return $this->result('delivered', null, $status);
            }
            if ($status === 408 || $status === 429 || $status >= 500) {
                $retry = $response->header('Retry-After');

                return $this->result('retrying', 'provider_retryable', $status,
                    is_string($retry) && ctype_digit($retry) ? max(10, min(300, (int) $retry)) : null);
            }

            return $this->result('failed', 'provider_rejected', $status);
        } catch (LengthException) {
            return $this->result('uncertain', 'response_too_large');
        } catch (Throwable $exception) {
            return $this->responseExceeded($exception, $sink)
                ? $this->result('uncertain', 'response_too_large')
                : $this->result('uncertain', 'transport_result_unknown');
        } finally {
            BoundedWebhookResponseSink::close($sink);
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function body(AlertDestination $destination, array $payload): array
    {
        $title = (string) ($payload['title'] ?? config('app.name', 'Deployer').' alert');
        $message = (string) ($payload['message'] ?? 'An event requires attention.');

        return match ($destination->type) {
            'slack' => ['text' => "*{$title}*\n{$message}"],
            'discord' => ['content' => "**{$title}**\n{$message}", 'allowed_mentions' => ['parse' => []]],
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
                            ['type' => 'TextBlock', 'weight' => 'Bolder', 'text' => $title],
                            ['type' => 'TextBlock', 'wrap' => true, 'text' => $message],
                        ],
                    ],
                ]],
            ],
            'pagerduty' => [
                'routing_key' => $destination->endpoint,
                'event_action' => ($payload['event'] ?? null) === 'recovery' ? 'resolve' : 'trigger',
                'dedup_key' => (string) ($payload['dedup_key'] ?? (($payload['category'] ?? 'event').'-'.($payload['resource_id'] ?? 0))),
                'payload' => [
                    'summary' => $title,
                    'source' => config('app.name', 'Deployer'),
                    'severity' => 'error',
                    'custom_details' => ['message' => $message],
                ],
            ],
            default => $payload,
        };
    }

    /** @return array{status: string, error_code: string|null, http_status: int|null, retry_after: int|null} */
    private function result(string $status, ?string $errorCode, ?int $httpStatus = null, ?int $retryAfter = null): array
    {
        return ['status' => $status, 'error_code' => $errorCode, 'http_status' => $httpStatus, 'retry_after' => $retryAfter];
    }

    /** Detect a bounded header callback failure wrapped by the HTTP client's request exception. */
    private function responseExceeded(Throwable $exception, mixed $sink): bool
    {
        if (BoundedWebhookResponseSink::exceeded($sink)) {
            return true;
        }

        do {
            if ($exception instanceof LengthException) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }

    /** Enforce the aggregate header and declared body limits at the header boundary. */
    private static function responseHeadersExceeded(ResponseInterface $response): bool
    {
        $headerBytes = 0;
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headerBytes += strlen($name) + strlen($value) + 4;
            }
        }

        return $headerBytes > self::MAX_RESPONSE_HEADER_BYTES
            || (is_numeric($response->getHeaderLine('Content-Length'))
                && (int) $response->getHeaderLine('Content-Length') > self::MAX_RESPONSE_BYTES);
    }
}
