<?php

namespace App\Services;

use App\Data\WebsiteHealthProbeResult;
use App\Models\Website;
use Throwable;

class WebsiteHealthProbe
{
    /**
     * Bind the remote website probe to the existing managed-server runner.
     *
     * @param  Runner  $runner  Executes the bounded health command on the website's server.
     */
    public function __construct(private readonly Runner $runner) {}

    /**
     * Execute one bounded health probe without persisting application state.
     *
     * Persistence and state-transition policy remain with the caller, allowing
     * periodic monitoring and revision-aware deployment observation to keep
     * separate histories and stale-result rules.
     *
     * @param  Website  $website  Website whose current URL and health path are probed.
     * @return WebsiteHealthProbeResult Sanitized probe outcome and bounded metrics.
     */
    public function probe(Website $website): WebsiteHealthProbeResult
    {
        $url = escapeshellarg("http://{$website->url}{$website->health_check_path}");
        $command = <<<BASH
        curl --fail --silent --show-error --location \
            --connect-timeout 5 --max-time 15 \
            --retry 1 --retry-delay 1 --retry-all-errors \
            --user-agent "lessbuild-health-monitor" \
            --output /dev/null --write-out '%{http_code} %{time_total}\\n' {$url}
        BASH;

        try {
            $process = $this->runner->server($website->server)->create()->execute($command);
        } catch (Throwable $exception) {
            report($exception);

            return new WebsiteHealthProbeResult(
                successful: false,
                error: str($exception->getMessage())->limit(500, '')->toString() ?: 'Unable to reach the managed server.',
                httpStatus: null,
                durationMs: null,
            );
        }

        $output = trim($process->getOutput());
        [$httpStatus, $durationMs] = $this->parseMetrics($output);

        if ($process->isSuccessful()) {
            return new WebsiteHealthProbeResult(
                successful: true,
                error: null,
                httpStatus: $httpStatus,
                durationMs: $durationMs,
            );
        }

        return new WebsiteHealthProbeResult(
            successful: false,
            error: str(trim($process->getErrorOutput()) ?: 'The website did not return a successful response.')
                ->limit(500, '')
                ->toString(),
            httpStatus: $httpStatus,
            durationMs: $durationMs,
        );
    }

    /** @return array{?int, ?int} */
    private function parseMetrics(string $output): array
    {
        if (! preg_match('/(?<!\d)(\d{3}) ([0-9]+(?:\.[0-9]+)?)\s*\z/', $output, $matches)) {
            return [null, null];
        }

        $httpStatus = (int) $matches[1];
        $durationMs = (int) round((float) $matches[2] * 1000);

        return [
            $httpStatus > 0 ? $httpStatus : null,
            max(0, min($durationMs, 4_294_967_295)),
        ];
    }
}
