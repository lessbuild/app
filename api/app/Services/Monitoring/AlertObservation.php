<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Enums\AlertMetric;
use App\Models\AlertRule;
use App\Models\Issue;
use App\Models\ServiceLevelObjective;
use App\Models\TelemetryEvent;
use App\Support\Telemetry\EventTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class AlertObservation
{
    /**
     * Create a new AlertObservation instance.
     *
     * Measures alert rules.
     *
     * @param  MetricAlertObservation  $metrics  Measures numeric metric rules.
     * @param  MetricAnomalyAlertObservation  $anomalies  Measures metric anomaly rules.
     * @param  LogPatternAlertObservation  $patterns  Measures log pattern rules.
     * @param  ServiceObjectiveReport  $objectives  Measures SLO burn-rate rules.
     */
    public function __construct(
        private readonly MetricAlertObservation $metrics,
        private readonly MetricAnomalyAlertObservation $anomalies,
        private readonly LogPatternAlertObservation $patterns,
        private readonly ServiceObjectiveReport $objectives,
    ) {}

    /**
     * Measure a rule over the window ending at `$until`: warming until the rule has watched a full window, then by
     * metric (numeric metrics, anomalies, log patterns, SLO burn, telemetry volume or freshness, or request error
     * rate, duration, exceptions and error logs from one grouped query). Too few samples is no data.
     *
     * @param  AlertRule  $rule
     * @param  CarbonImmutable  $until
     * @return array<string, mixed> state (warming, no_data, maintenance, breaching or healthy), value, samples, window and reason
     */
    public function measure(AlertRule $rule, CarbonImmutable $until): array
    {
        $from = $until->subMinutes($rule->window_minutes);
        $window = ['from' => $from->toISOString(), 'until' => $until->toISOString()];
        if ($from->lessThan($rule->monitoring_since)) {
            return ['state' => 'warming', 'value' => null, 'samples' => 0, ...$window];
        }
        if ($rule->metric === AlertMetric::NumericMetric) {
            return [...$this->metrics->measure($rule, $from, $until), ...$window];
        }
        if ($rule->metric === AlertMetric::MetricAnomaly) {
            return [...$this->anomalies->measure($rule, $from, $until), ...$window];
        }
        if ($rule->metric === AlertMetric::LogPatternCount) {
            return [...$this->patterns->measure($rule, $from, $until), ...$window];
        }

        if ($rule->metric === AlertMetric::SloBurnRate) {
            $objective = ServiceLevelObjective::query()->where('environment_id', $rule->environment_id)
                ->whereKey($rule->service_level_objective_id)->where('enabled', true)->first();
            if ($objective === null) {
                return ['state' => 'no_data', 'value' => null, 'samples' => 0, ...$window];
            }
            $report = $this->objectives->forObjective($objective, $until);
            $value = $report['burn_rate'];
            $samples = $report['observed'];

            return [
                'state' => $value === null || $samples < $rule->minimum_samples
                    ? 'no_data' : ($value >= $rule->threshold ? 'breaching' : 'healthy'),
                'value' => $value, 'samples' => $samples, ...$window,
            ];
        }

        if ($rule->metric === AlertMetric::TelemetryVolume) {
            $value = (float) $this->events($rule)
                ->where('occurred_at', '>=', EventTime::boundary($from))
                ->where('occurred_at', '<', EventTime::boundary($until))->count();

            return [
                'state' => $value <= $rule->threshold ? 'breaching' : 'healthy',
                'value' => $value, 'samples' => (int) $value, ...$window,
            ];
        }

        if ($rule->metric === AlertMetric::TelemetryFreshness) {
            $latest = $this->events($rule)
                ->where('occurred_at', '>=', EventTime::boundary($rule->monitoring_since))
                ->where('occurred_at', '<', EventTime::boundary($until))->max('occurred_at');
            $latestAt = $latest === null ? $rule->monitoring_since : CarbonImmutable::parse((string) $latest, 'UTC');
            $value = (float) max(0, $latestAt->diffInSeconds($until, false));

            return [
                'state' => $value >= $rule->threshold ? 'breaching' : 'healthy',
                'value' => $value, 'samples' => $latest === null ? 0 : 1, ...$window,
            ];
        }

        if ($rule->metric === AlertMetric::ExceptionIncrease) {
            return [...$this->exceptionIncrease($rule, $from, $until), ...$window];
        }
        if ($rule->metric === AlertMetric::CriticalIssueCount) {
            return [...$this->criticalIssues($rule, $from, $until), ...$window];
        }

        $query = $this->events($rule)
            ->where('occurred_at', '>=', EventTime::boundary($from))
            ->where('occurred_at', '<', EventTime::boundary($until));
        $row = $query->toBase()
            ->selectRaw('COUNT(*) AS events')
            ->selectRaw("COUNT(CASE WHEN type = 'request' THEN 1 END) AS requests")
            ->selectRaw("COUNT(CASE WHEN type = 'request' AND duration_ms >= 0 THEN 1 END) AS timed")
            ->selectRaw("AVG(CASE WHEN type = 'request' AND duration_ms >= 0 THEN duration_ms END) AS duration")
            ->selectRaw("COUNT(CASE WHEN type = 'request' AND (status_code BETWEEN 500 AND 599 OR severity IN ('error', 'critical')) THEN 1 END) AS failed")
            ->selectRaw("COUNT(CASE WHEN type = 'exception' THEN 1 END) AS exceptions")
            ->selectRaw("COUNT(CASE WHEN type = 'log' AND severity IN ('error', 'critical') THEN 1 END) AS error_logs")->first();
        if ($row === null) {
            return ['state' => 'no_data', 'value' => null, 'samples' => 0, ...$window];
        }

        $samples = (int) match ($rule->metric) {
            AlertMetric::RequestErrorRate => $row->requests,
            AlertMetric::RequestDuration => $row->timed,
            default => $row->events,
        };
        $value = match ($rule->metric) {
            AlertMetric::RequestErrorRate => $samples > 0 ? $row->failed / $samples * 100 : null,
            AlertMetric::RequestDuration => $row->duration !== null ? (float) $row->duration : null,
            AlertMetric::ExceptionCount => (float) $row->exceptions,
            AlertMetric::ErrorLogCount => (float) $row->error_logs,
        };

        return [
            'state' => $samples < $rule->minimum_samples || $value === null
                ? 'no_data' : ($value >= $rule->threshold ? 'breaching' : 'healthy'),
            'value' => $value, 'samples' => $samples, ...$window,
        ];
    }

    /**
     * Compare exception events in this window with the preceding window. The value is the absolute increase, so a
     * threshold of 10 opens an incident when there are at least 10 more exceptions than in the previous window.
     * Other telemetry in the current window counts as samples, allowing the alert to recover when exceptions stop.
     *
     * @param  AlertRule  $rule
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return array{state: string, value: float|null, samples: int}
     */
    private function exceptionIncrease(AlertRule $rule, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $previousFrom = $from->subMinutes($rule->window_minutes);
        $row = $this->events($rule)->where('occurred_at', '>=', EventTime::boundary($previousFrom))
            ->where('occurred_at', '<', EventTime::boundary($until))
            ->selectRaw('COUNT(CASE WHEN type = ? AND occurred_at >= ? THEN 1 END) AS current_exceptions', ['exception', EventTime::boundary($from)])
            ->selectRaw('COUNT(CASE WHEN type = ? AND occurred_at < ? THEN 1 END) AS previous_exceptions', ['exception', EventTime::boundary($from)])
            ->selectRaw('COUNT(CASE WHEN occurred_at >= ? THEN 1 END) AS samples', [EventTime::boundary($from)])
            ->toBase()->first();
        $values = (array) $row;
        $samples = (int) ($values['samples'] ?? 0);
        $increase = max(0, (int) ($values['current_exceptions'] ?? 0) - (int) ($values['previous_exceptions'] ?? 0));

        return [
            'state' => $samples < $rule->minimum_samples ? 'no_data' : ($increase >= $rule->threshold ? 'breaching' : 'healthy'),
            'value' => (float) $increase, 'samples' => $samples,
        ];
    }

    /**
     * Count critical issues first seen in this rule's environment during the window. Telemetry in the environment is
     * the sample count, so a quiet window with incoming traffic is healthy rather than no-data.
     *
     * @param  AlertRule  $rule
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return array{state: string, value: float|null, samples: int}
     */
    private function criticalIssues(AlertRule $rule, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $samples = $this->events($rule)->where('occurred_at', '>=', EventTime::boundary($from))
            ->where('occurred_at', '<', EventTime::boundary($until))->count();
        $count = Issue::query()->where('type', 'exception')->where('severity', 'critical')
            ->where('first_seen_at', '>=', EventTime::boundary($from))->where('first_seen_at', '<', EventTime::boundary($until))
            ->whereHas('telemetryEvents', fn (Builder $events): Builder => $events->where('environment_id', $rule->environment_id)
                ->where('type', 'exception')->when($rule->service !== null, fn (Builder $query): Builder => $query->where('service', $rule->service))
                ->where('occurred_at', '>=', EventTime::boundary($from))->where('occurred_at', '<', EventTime::boundary($until)))
            ->count();

        return [
            'state' => $samples < $rule->minimum_samples ? 'no_data' : ($count >= $rule->threshold ? 'breaching' : 'healthy'),
            'value' => (float) $count, 'samples' => $samples,
        ];
    }

    /**
     * Query the rule's environment's events, narrowed to its service when it has one.
     *
     * @param  AlertRule  $rule
     * @return Builder<TelemetryEvent>
     */
    private function events(AlertRule $rule): Builder
    {
        return TelemetryEvent::query()->where('environment_id', $rule->environment_id)
            ->when($rule->service !== null, fn (Builder $query): Builder => $query->where('service', $rule->service));
    }
}
