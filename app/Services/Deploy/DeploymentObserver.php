<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Build;
use App\Models\TelemetryEvent;
use App\Support\Telemetry\EventTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Watches a website's health for a while after a deploy goes live (the environment's observation minutes): a failing
 * health check fails the observation and, if the environment asks for it, rolls back to the previous release.
 */
class DeploymentObserver
{
    /**
     * Create a new DeploymentObserver instance.
     *
     * Observes deploys after they go live.
     *
     * @param  Factory  $http  Requests the health URL.
     * @param  Deployments  $deployments  Rolls back when the environment asks for it.
     */
    public function __construct(private readonly Factory $http, private readonly Deployments $deployments) {}

    /**
     * Check the website's health once for an observed build: a newer live build supersedes it, a failed check or a jump
     * in failed requests fails it (and may roll back), and a healthy check past the deadline passes it.
     *
     * @param  Build  $build
     * @return string passed, failed, superseded or observing
     */
    public function check(Build $build): string
    {
        $website = $build->website;
        $newer = Build::query()->where('website_id', $build->website_id)->where('id', '>', $build->id)->whereNotNull('activated_at')->exists();
        if ($newer) {
            return $this->finish($build, 'superseded');
        }
        $url = 'https://'.$website->url.($website->health_check_enabled ? $website->health_check_path : '/');
        try {
            $healthy = $this->http->connectTimeout(5)->timeout(15)->withoutRedirecting()->get($url)->successful();
            $error = $healthy ? null : (string) __('The health check at :url didn’t answer with a success status.', ['url' => $url]);
        } catch (Throwable) {
            [$healthy, $error] = [false, (string) __('The health check at :url couldn’t be reached.', ['url' => $url])];
        }
        if ($healthy) {
            $error = $this->errorSpike($build);
            $healthy = $error === null;
        }
        if (! $healthy) {
            $this->finish($build, 'failed', $error);
            $this->deployments->rollBackAutomatically($build->loadMissing('environment'));

            return 'failed';
        }

        return $build->observation_deadline_at !== null && $build->observation_deadline_at->isPast() ? $this->finish($build, 'passed') : 'observing';
    }

    /**
     * Describe a jump in failed requests since the build went live, or return null when there isn't one (or the
     * environment doesn't watch for it). It's a jump when more than the environment's threshold of at least 20
     * requests failed, and the rate is higher than over the same length of time before the deploy.
     *
     * @param  Build  $build
     * @return string|null
     */
    private function errorSpike(Build $build): ?string
    {
        $threshold = $build->environment?->rollback_error_rate_percent;
        if ($threshold === null || $build->environment_id === null || $build->activated_at === null) {
            return null;
        }
        $live = CarbonImmutable::instance($build->activated_at)->utc();
        $now = CarbonImmutable::now('UTC');
        $after = $this->failures($build->environment_id, $live, $now);
        if ($after['requests'] < 20) {
            return null;
        }
        $rate = $after['failed'] / $after['requests'] * 100;
        $before = $this->failures($build->environment_id, $live->subSeconds(max(60, (int) $live->diffInSeconds($now))), $live);
        $previous = $before['requests'] > 0 ? $before['failed'] / $before['requests'] * 100 : 0.0;
        if ($rate <= $threshold || $rate <= $previous) {
            return null;
        }

        return (string) __(':rate% of requests failed after the deploy (:before% before it), over the :threshold% limit.', [
            'rate' => round($rate, 1), 'before' => round($previous, 1), 'threshold' => $threshold,
        ]);
    }

    /**
     * Count the environment's requests and failed requests (5xx, or logged as errors) in a period.
     *
     * @param  string  $environmentId
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return array{requests: int, failed: int}
     */
    private function failures(string $environmentId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $row = TelemetryEvent::query()->where('environment_id', $environmentId)->where('type', 'request')
            ->where('occurred_at', '>=', EventTime::boundary($from))->where('occurred_at', '<', EventTime::boundary($until))
            ->toBase()->selectRaw('COUNT(*) AS requests')
            ->selectRaw("COUNT(CASE WHEN status_code BETWEEN 500 AND 599 OR severity IN ('error', 'critical') THEN 1 END) AS failed")
            ->first();

        return ['requests' => (int) ($row->requests ?? 0), 'failed' => (int) ($row->failed ?? 0)];
    }

    /**
     * Record the observation's outcome.
     *
     * @param  Build  $build
     * @param  string  $status
     * @param  string|null  $error
     * @return string
     */
    private function finish(Build $build, string $status, ?string $error = null): string
    {
        $build->forceFill(['observation_status' => $status, 'observation_error' => $error])->save();

        return $status;
    }
}
