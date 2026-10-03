<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
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
     * Compare the release with the same length of time before it went live, record the comparison on the build, and
     * say why it should be rolled back, if it should: failed requests over the environment's limit (and above the
     * rate before), average request time up by more than its limit, or the Analytics conversion rate down by more
     * than its limit. Each needs enough traffic on both sides to judge.
     *
     * @param  Build  $build
     * @return string|null
     */
    private function errorSpike(Build $build): ?string
    {
        $environment = $build->environment;
        if ($environment === null || $build->environment_id === null || $build->activated_at === null) {
            return null;
        }
        $live = CarbonImmutable::instance($build->activated_at)->utc();
        $now = CarbonImmutable::now('UTC');
        $before = $live->subSeconds(max(60, (int) $live->diffInSeconds($now)));
        $after = [...$this->requests($build->environment_id, $live, $now), ...$this->conversions($build->environment_id, $live, $now)];
        $previous = [...$this->requests($build->environment_id, $before, $live), ...$this->conversions($build->environment_id, $before, $live)];
        $build->forceFill(['observation_report' => ['before' => $previous, 'after' => $after, 'checked_at' => $now->toIso8601String()]])->save();

        $errors = $environment->rollback_error_rate_percent;
        if ($errors !== null && $after['requests'] >= 20 && $after['error_rate'] > $errors && $after['error_rate'] > $previous['error_rate']) {
            return (string) __(':rate% of requests failed after the deploy (:before% before it), over the :threshold% limit.', [
                'rate' => $after['error_rate'], 'before' => $previous['error_rate'], 'threshold' => $errors,
            ]);
        }
        $latency = $environment->rollback_latency_percent;
        if ($latency !== null && $after['requests'] >= 20 && $previous['requests'] >= 20 && $after['latency_ms'] !== null && $previous['latency_ms'] !== null && $previous['latency_ms'] > 0
            && ($after['latency_ms'] - $previous['latency_ms']) / $previous['latency_ms'] * 100 > $latency) {
            return (string) __('Requests took :after ms on average after the deploy (:before ms before it), over :threshold% slower.', [
                'after' => $after['latency_ms'], 'before' => $previous['latency_ms'], 'threshold' => $latency,
            ]);
        }
        $conversions = $environment->rollback_conversion_drop_percent;
        [$rateBefore, $rateAfter] = [$previous['conversion_rate'], $after['conversion_rate']];
        if ($conversions !== null && $rateBefore !== null && $rateAfter !== null && $rateBefore > 0 && $after['visits'] >= 50 && $previous['visits'] >= 50
            && ($rateBefore - $rateAfter) / $rateBefore * 100 > $conversions) {
            return (string) __('The conversion rate fell to :after% after the deploy (:before% before it), down by over :threshold%.', [
                'after' => $rateAfter, 'before' => $rateBefore, 'threshold' => $conversions,
            ]);
        }

        return null;
    }

    /**
     * Count the environment's requests in a window from its Monitoring telemetry: how many, the share that failed
     * (5xx or errors) and the average time.
     *
     * @param  string  $environmentId
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return array{requests: int, error_rate: float, latency_ms: float|null}
     */
    private function requests(string $environmentId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $row = TelemetryEvent::query()->where('environment_id', $environmentId)->where('type', 'request')
            ->where('occurred_at', '>=', EventTime::boundary($from))->where('occurred_at', '<', EventTime::boundary($until))
            ->toBase()->selectRaw('COUNT(*) AS requests, AVG(duration_ms) AS latency')
            ->selectRaw("COUNT(CASE WHEN status_code BETWEEN 500 AND 599 OR severity IN ('error', 'critical') THEN 1 END) AS failed")
            ->first();
        $requests = (int) ($row->requests ?? 0);

        return [
            'requests' => $requests,
            'error_rate' => $requests > 0 ? round((int) ($row->failed ?? 0) / $requests * 100, 1) : 0.0,
            'latency_ms' => $row?->latency !== null ? round((float) $row->latency, 1) : null,
        ];
    }

    /**
     * Count the visits to the environment's Analytics site that started in a window and the share that converted, or
     * nothing when the environment has no site.
     *
     * @param  string  $environmentId
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return array{visits: int|null, conversion_rate: float|null}
     */
    private function conversions(string $environmentId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $sites = AnalyticsSite::query()->where('environment_id', $environmentId)->pluck('id');
        if ($sites->isEmpty()) {
            return ['visits' => null, 'conversion_rate' => null];
        }
        $row = AnalyticsVisit::query()->whereIn('site_id', $sites)->where('started_at', '>=', $from)->where('started_at', '<', $until)
            ->toBase()->selectRaw('COUNT(*) AS visits, COUNT(CASE WHEN conversion_count > 0 THEN 1 END) AS converted')->first();
        $visits = (int) ($row->visits ?? 0);

        return ['visits' => $visits, 'conversion_rate' => $visits > 0 ? round((int) ($row->converted ?? 0) / $visits * 100, 2) : 0.0];
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
