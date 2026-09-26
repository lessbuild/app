<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Models\WebsiteHealthCheck;
use Illuminate\Support\Facades\DB;

class WebsiteHealthMonitor
{
    /**
     * Bind probe execution, state persistence and incident reporting for website health checks.
     *
     * @param  WebsiteHealthProbe  $probe  Executes the bounded website probe without persistence.
     * @param  ActivityRecorder  $activity  Records health-state transitions.
     * @param  IncidentNotifier  $incidents  Persists and notifies failure and recovery events.
     */
    public function __construct(
        private readonly WebsiteHealthProbe $probe,
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
        $website->loadMissing(['server', 'environments']);
        if (! $website->health_check_enabled
            || ($automatic && ! $website->health_monitoring_enabled)
            || ($automatic && $website->environments->contains(fn ($environment) => $environment->hibernated_at !== null))
            || $website->provisioning_status !== Website::STATUS_ACTIVE
            || $website->server?->provisioning_status !== Server::STATUS_ACTIVE) {
            return null;
        }

        $result = $this->probe->probe($website);

        return $this->recordResult(
            $website,
            $result->successful,
            $result->error,
            $result->httpStatus,
            $result->durationMs,
            $automatic,
        );
    }

    /**
     * Persist a health probe only if its website configuration and prior-check snapshot still match.
     *
     * @param  Website  $website  The probed website snapshot used to reject stale results.
     * @param  bool  $successful  Whether the probe returned a successful health response.
     * @param  string|null  $error  The optional failure detail.
     * @param  int|null  $httpStatus  The observed HTTP status, if available.
     * @param  int|null  $durationMs  The measured probe duration in milliseconds, if available.
     * @param  bool  $automatic  Whether this probe requires automatic monitoring to remain enabled.
     * @return bool True only on a new unhealthy transition; false for stale, healthy or unchanged-state results.
     */
    private function recordResult(
        Website $website,
        bool $successful,
        ?string $error,
        ?int $httpStatus,
        ?int $durationMs,
        bool $automatic,
    ): bool {
        return DB::connection('deployer')->transaction(function () use ($website, $successful, $error, $httpStatus, $durationMs, $automatic): bool {
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
            $threshold = in_array($locked->health_failure_threshold, Website::HEALTH_FAILURE_THRESHOLDS, true)
                ? $locked->health_failure_threshold
                : Website::DEFAULT_HEALTH_FAILURE_THRESHOLD;
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
