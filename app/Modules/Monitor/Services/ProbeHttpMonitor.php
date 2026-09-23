<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\MonitorObservation;
use App\Modules\Monitor\Models\Monitor;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LengthException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class ProbeHttpMonitor
{
    public const BODY_LIMIT = 524288;

    public function __construct(private readonly PublicHttpTarget $targets) {}

    public function probe(Monitor $monitor): MonitorObservation
    {
        $start = hrtime(true);
        try {
            $target = $this->targets->resolve($monitor->request_url);
        } catch (Throwable) {
            return new MonitorObservation('unknown', 'dns_unavailable');
        }
        $dnsMs = round((hrtime(true) - $start) / 1000000, 3);
        if ($target['error'] !== null) {
            return new MonitorObservation('unknown', $target['error'], dnsMs: $dnsMs);
        }
        if (! extension_loaded('curl')) {
            return new MonitorObservation('unknown', 'checker_unavailable');
        }
        $stream = Utils::streamFor('');
        $size = 0;
        $oversized = false;
        $sink = FnStream::decorate($stream, ['write' => function (string $chunk) use ($stream, &$size, &$oversized): int {
            $size += strlen($chunk);
            if ($size > self::BODY_LIMIT) {
                $oversized = true;
                throw new LengthException('Monitor response exceeded its limit.');
            }

            return $stream->write($chunk);
        }]);
        $address = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];
        $curl = [
            CURLOPT_PROXY => '', CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if (! $target['literal']) {
            $curl[CURLOPT_RESOLVE] = [$target['host'].':'.$target['port'].':'.$address];
        }
        try {
            $request = Http::withHeaders(['User-Agent' => 'Beacon-Monitor/1.0', 'Accept' => '*/*'])
                ->connectTimeout(min(5, $monitor->timeout_seconds))->timeout($monitor->timeout_seconds)
                ->withoutRedirecting()->setHandler(new CurlHandler)
                ->withOptions([
                    'proxy' => '', 'verify' => true, 'idn_conversion' => false, 'sink' => $sink, 'curl' => $curl,
                    'on_headers' => static function (ResponseInterface $response) use (&$oversized, $monitor): void {
                        if ($monitor->method !== 'HEAD' && (int) $response->getHeaderLine('Content-Length') > self::BODY_LIMIT) {
                            $oversized = true;
                            throw new LengthException('Monitor response exceeded its limit.');
                        }
                    },
                ]);
            if ($monitor->bearer_token !== null) {
                if ($target['scheme'] !== 'https') {
                    return new MonitorObservation('unknown', 'target_invalid');
                }
                $request->withToken($monitor->bearer_token);
            }
            $response = $request->send($monitor->method, $monitor->request_url);
            $durationMs = round((hrtime(true) - $start) / 1000000, 3);
            if (strlen($response->body()) > self::BODY_LIMIT) {
                return new MonitorObservation('unknown', 'response_too_large');
            }
            $stats = $response->handlerStats();
            if (isset($stats['total_time'])) {
                $durationMs = $dnsMs + round($stats['total_time'] * 1000, 3);
            }
            $reason = match (true) {
                $response->status() < $monitor->status_min || $response->status() > $monitor->status_max => 'unexpected_status',
                $monitor->body_contains !== null && ! str_contains($response->body(), $monitor->body_contains) => 'body_mismatch',
                $monitor->max_duration_ms !== null && $durationMs > $monitor->max_duration_ms => 'too_slow',
                default => 'passed',
            };

            return new MonitorObservation(
                $reason === 'passed' ? 'up' : 'down', $reason, $response->status(), $durationMs, $dnsMs,
                isset($stats['connect_time']) ? round($stats['connect_time'] * 1000, 3) : null,
                isset($stats['starttransfer_time']) ? round($stats['starttransfer_time'] * 1000, 3) : null,
            );
        } catch (ConnectionException) {
            if ($oversized) {
                return new MonitorObservation('unknown', 'response_too_large');
            }

            return new MonitorObservation('down', 'connection_failed', durationMs: round((hrtime(true) - $start) / 1000000, 3), dnsMs: $dnsMs);
        } catch (Throwable) {
            return new MonitorObservation('unknown', $oversized ? 'response_too_large' : 'checker_unavailable');
        } finally {
            $sink->close();
        }
    }
}
