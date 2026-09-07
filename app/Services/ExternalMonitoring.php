<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class ExternalMonitoring
{
    /** @return array{name: string, passed: bool, detail: string} */
    public function configurationCheck(): array
    {
        if (config('app.env') !== 'production') {
            return ['name' => 'External monitoring', 'passed' => true, 'detail' => 'Not required outside production'];
        }

        $heartbeatUrl = config('monitoring.heartbeat_url');
        $statusUrl = config('monitoring.status_url');
        $applicationHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $heartbeatHost = parse_url((string) $heartbeatUrl, PHP_URL_HOST);
        $statusHost = parse_url((string) $statusUrl, PHP_URL_HOST);
        $configured = $this->isSecureUrl($heartbeatUrl)
            && $this->isSecureUrl($statusUrl)
            && is_string($heartbeatHost)
            && is_string($statusHost)
            && strcasecmp((string) $applicationHost, $heartbeatHost) !== 0
            && strcasecmp((string) $applicationHost, $statusHost) !== 0;

        return [
            'name' => 'External monitoring',
            'passed' => $configured,
            'detail' => $configured
                ? 'Heartbeat and independent status destinations are configured'
                : 'Configure an external heartbeat and independently hosted status page',
        ];
    }

    /**
     * Request the configured HTTPS heartbeat endpoint using bounded retries.
     *
     * @return bool True for a successful response; false for invalid configuration or a reported request failure.
     */
    public function sendHeartbeat(): bool
    {
        $url = config('monitoring.heartbeat_url');
        if (! $this->isSecureUrl($url)) {
            return false;
        }

        try {
            return Http::timeout(max(1, (int) config('monitoring.timeout_seconds', 10)))
                ->retry(2, 200)
                ->withHeaders(['User-Agent' => 'BuildPusher-heartbeat'])
                ->get($url)
                ->successful();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Probe the configured external status-page URL over HTTPS.
     *
     * @return bool True for a successful response; false for invalid configuration or a reported request failure.
     */
    public function statusPageIsReachable(): bool
    {
        $url = config('monitoring.status_url');
        if (! $this->isSecureUrl($url)) {
            return false;
        }

        try {
            return Http::timeout(max(1, (int) config('monitoring.timeout_seconds', 10)))
                ->retry(2, 200)
                ->withHeaders(['User-Agent' => 'BuildPusher-monitoring-audit'])
                ->get($url)
                ->successful();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Check that a configured monitoring endpoint is a valid HTTPS URL with a host.
     *
     * @param  mixed  $url  The untrusted configuration value to inspect.
     * @return bool Whether the URL passes syntax, scheme and host checks; DNS is not resolved here.
     */
    private function isSecureUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && filled(parse_url($url, PHP_URL_HOST));
    }
}
