<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Website;
use App\Models\WebsiteHealthCheck;
use Illuminate\Support\Facades\DB;
use Throwable;

class WebsiteHealthMonitor
{
    public function __construct(
        private readonly Runner $runner,
        private readonly ActivityRecorder $activity,
        private readonly IncidentNotifier $incidents,
    ) {}

    /**
     * Check one website.
     *
     * Returns null when the website is no longer eligible, true when a new
     * outage is recorded, and false for every other completed result.
     */
    public function check(Website $website, bool $automatic = false): ?bool
    {
        $website->loadMissing('server');
        if (! $website->health_check_enabled
            || ($automatic && ! $website->health_monitoring_enabled)
            || $website->provisioning_status !== Website::STATUS_ACTIVE
            || $website->server?->provisioning_status !== Server::STATUS_ACTIVE) {
            return null;
        }

        [$successful, $error, $httpStatus, $durationMs] = $this->execute($website);

        return $this->recordResult($website, $successful, $error, $httpStatus, $durationMs, $automatic);
    }

    /** @return array{bool, ?string, ?int, ?int} */
    private function execute(Website $website): array
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

            return [false, str($exception->getMessage())->limit(500)->toString() ?: 'Unable to reach the managed server.', null, null];
        }

        $output = trim($process->getOutput());
        [$httpStatus, $durationMs] = $this->parseMetrics($output);

        if ($process->isSuccessful()) {
            return [true, null, $httpStatus, $durationMs];
        }

        $error = trim($process->getErrorOutput());

        return [
            false,
            str($error ?: 'The website did not return a successful response.')->limit(500)->toString(),
            $httpStatus,
            $durationMs,
        ];
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

    private function recordResult(
        Website $website,
        bool $successful,
        ?string $error,
        ?int $httpStatus,
        ?int $durationMs,
        bool $automatic,
    ): bool {
        return DB::transaction(function () use ($website, $successful, $error, $httpStatus, $durationMs, $automatic): bool {
            $locked = Website::query()->lockForUpdate()->find($website->id);
            if (! $locked
                || ! $locked->health_check_enabled
                || ($automatic && ! $locked->health_monitoring_enabled)
                || $locked->provisioning_status !== Website::STATUS_ACTIVE
                || $locked->server?->provisioning_status !== Server::STATUS_ACTIVE
                || $locked->server_id !== $website->server_id
                || $locked->url !== $website->url
                || $locked->health_check_path !== $website->health_check_path
                || $locked->getRawOriginal('health_last_checked_at') !== $website->getRawOriginal('health_last_checked_at')) {
                return false;
            }

            $checkedAt = now();
            $locked->healthChecks()->create([
                'successful' => $successful,
                'source' => $automatic ? WebsiteHealthCheck::SOURCE_AUTOMATIC : WebsiteHealthCheck::SOURCE_MANUAL,
                'http_status' => $httpStatus,
                'duration_ms' => $durationMs,
                'endpoint' => str("http://{$locked->url}{$locked->health_check_path}")->limit(512, '')->toString(),
                'error' => $error === null ? null : str($error)->limit(500, '')->toString(),
                'checked_at' => $checkedAt,
            ]);
            $retainedIds = $locked->healthChecks()
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->limit(WebsiteHealthCheck::MAX_PER_WEBSITE)
                ->pluck('id');
            $locked->healthChecks()->whereNotIn('id', $retainedIds)->delete();

            $previousStatus = $locked->health_status;
            if ($successful) {
                $locked->update([
                    'health_status' => Website::HEALTH_HEALTHY,
                    'health_failure_count' => 0,
                    'health_last_checked_at' => $checkedAt,
                    'health_last_error' => null,
                ]);
                if ($previousStatus === Website::HEALTH_UNHEALTHY) {
                    $this->activity->record($locked, $locked->user_id, 'website', "Website \"{$locked->name}\" recovered.");
                    if ($locked->user) {
                        $this->incidents->recover(
                            $locked->user,
                            'website',
                            $locked->id,
                            "Website \"{$locked->name}\" recovered",
                            __('The website returned a successful health response again.'),
                        );
                    }
                }

                return false;
            }

            $failureCount = min(65535, $locked->health_failure_count + 1);
            $threshold = max(1, (int) config('lessbuild.health_monitor_failure_threshold'));
            $nextStatus = $failureCount >= $threshold ? Website::HEALTH_UNHEALTHY : $previousStatus;
            $locked->update([
                'health_status' => $nextStatus,
                'health_failure_count' => $failureCount,
                'health_last_checked_at' => $checkedAt,
                'health_last_error' => $error,
            ]);
            if ($nextStatus !== Website::HEALTH_UNHEALTHY || $previousStatus === Website::HEALTH_UNHEALTHY) {
                return false;
            }

            $this->activity->record($locked, $locked->user_id, 'website', "Website \"{$locked->name}\" is unhealthy.");
            if ($locked->user) {
                $this->incidents->fail(
                    $locked->user,
                    'website',
                    $locked->id,
                    "Website \"{$locked->name}\" is unhealthy",
                    $error ?: 'The website did not return a successful response.',
                );
            }

            return true;
        });
    }
}
