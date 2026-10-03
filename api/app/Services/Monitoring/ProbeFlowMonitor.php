<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\Monitoring\MonitorObservation;
use App\Models\Monitor;
use App\Support\Monitoring\FlowSteps;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Runs a multi-step check: each step's request in order, sharing cookies, carrying extracted values ({{name}}) into
 * later steps and following redirects itself (re-checking each address is public). It stops at the first step that
 * fails; the whole flow must finish within the monitor's timeout for each step.
 */
final class ProbeFlowMonitor
{
    /**
     * The most redirects followed per step.
     *
     * @var int
     */
    private const MAX_REDIRECTS = 5;

    /**
     * Create a new ProbeFlowMonitor instance.
     *
     * @param  PublicHttpTarget  $targets  Makes sure every address is public.
     */
    public function __construct(private readonly PublicHttpTarget $targets) {}

    /**
     * Run the steps and report up, or down with the step that failed and why.
     *
     * @param  Monitor  $monitor
     * @return MonitorObservation
     */
    public function probe(Monitor $monitor): MonitorObservation
    {
        $parsed = FlowSteps::parse((string) $monitor->flow_steps);
        if ($parsed['error'] !== null) {
            return new MonitorObservation('unknown', 'target_invalid');
        }
        $jar = new CookieJar;
        $values = [];
        $start = hrtime(true);
        foreach ($parsed['steps'] as $index => $step) {
            $number = $index + 1;
            $fill = fn (string $text): string => (string) preg_replace_callback('/\{\{(\w+)\}\}/', fn (array $match): string => $values[$match[1]] ?? '', $text);
            $url = $fill($step['url']);
            $method = $step['method'];
            for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
                $target = $this->targets->resolve($url);
                if ($target['error'] !== null) {
                    return $this->failed('dns_unavailable', $number, $start);
                }
                $address = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];
                $request = Http::withHeaders(['User-Agent' => 'Beacon-Monitor/1.0 (multi-step)', 'Accept' => '*/*', ...array_map($fill, $step['headers'])])
                    ->connectTimeout(min(5, $monitor->timeout_seconds))->timeout($monitor->timeout_seconds)
                    ->withoutRedirecting()->setHandler(new CurlHandler)
                    ->withOptions(['cookies' => $jar, 'proxy' => '', 'verify' => true, 'curl' => $target['literal'] ? [] : [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$address]]]);
                if ($redirect === 0 && $step['form'] !== null) {
                    parse_str($fill($step['form']), $form);
                    $request = $request->asForm()->withBody(http_build_query($form), 'application/x-www-form-urlencoded');
                } elseif ($redirect === 0 && $step['json'] !== null) {
                    $request = $request->withBody($fill($step['json']), 'application/json');
                }
                try {
                    $response = $request->send($method, $url);
                } catch (ConnectionException) {
                    return $this->failed('connection_failed', $number, $start);
                } catch (Throwable) {
                    return new MonitorObservation('unknown', 'checker_unavailable');
                }
                $location = $response->header('Location');
                if (! in_array($response->status(), [301, 302, 303, 307, 308], true) || $location === '' || $step['status'] !== null && in_array($step['status'], [301, 302, 303, 307, 308], true)) {
                    break;
                }
                $url = (string) \GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($url), new \GuzzleHttp\Psr7\Uri($location));
                $method = in_array($response->status(), [307, 308], true) ? $method : 'GET';
            }
            $ok = $step['status'] !== null ? $response->status() === $step['status'] : $response->successful();
            if (! $ok) {
                return $this->failed('unexpected_status', $number, $start, $response->status());
            }
            $body = mb_substr($response->body(), 0, ProbeHttpMonitor::BODY_LIMIT);
            if ($step['text'] !== null && ! str_contains($body, $fill($step['text']))) {
                return $this->failed('body_mismatch', $number, $start, $response->status());
            }
            foreach ($step['extract'] as $name => $pattern) {
                if (preg_match('~'.str_replace('~', '\~', $pattern).'~', $body, $match) !== 1) {
                    return $this->failed('extract_failed', $number, $start, $response->status());
                }
                $values[$name] = $match[1] ?? $match[0];
            }
        }

        return new MonitorObservation('up', 'passed', durationMs: round((hrtime(true) - $start) / 1000000, 3), details: ['steps' => count($parsed['steps'])]);
    }

    /**
     * Report the flow as down at a step.
     *
     * @param  string  $reason
     * @param  int  $step
     * @param  int|float  $start
     * @param  int|null  $status
     * @return MonitorObservation
     */
    private function failed(string $reason, int $step, int|float $start, ?int $status = null): MonitorObservation
    {
        return new MonitorObservation($reason === 'dns_unavailable' ? 'unknown' : 'down', $reason, $status, round((hrtime(true) - $start) / 1000000, 3), details: ['failed_step' => $step]);
    }
}
