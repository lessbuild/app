<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\AlertMetric;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Models\TelemetryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class AlertObservation
{
    public function __construct(
        private readonly MetricAlertObservation $metrics,
        private readonly MetricAnomalyAlertObservation $anomalies,
        private readonly LogPatternAlertObservation $patterns,
        private readonly ServiceObjectiveReport $objectives,
    ) {}

    /** @return array{state: string, value: float|null, samples: int, from: string, until: string} */
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
                ->where('occurred_at', '>=', $from->format('Y-m-d H:i:s'))
                ->where('occurred_at', '<', $until->format('Y-m-d H:i:s'))->count();

            return [
                'state' => $value <= $rule->threshold ? 'breaching' : 'healthy',
                'value' => $value, 'samples' => (int) $value, ...$window,
            ];
        }

        if ($rule->metric === AlertMetric::TelemetryFreshness) {
            $latest = $this->events($rule)
                ->where('occurred_at', '>=', $rule->monitoring_since->format('Y-m-d H:i:s'))
                ->where('occurred_at', '<', $until->format('Y-m-d H:i:s'))->max('occurred_at');
            $latestAt = $latest === null ? $rule->monitoring_since : CarbonImmutable::parse((string) $latest, 'UTC');
            $value = (float) max(0, $latestAt->diffInSeconds($until, false));

            return [
                'state' => $value >= $rule->threshold ? 'breaching' : 'healthy',
                'value' => $value, 'samples' => $latest === null ? 0 : 1, ...$window,
            ];
        }

        $query = $this->events($rule)
            ->where('occurred_at', '>=', $from->format('Y-m-d H:i:s'))
            ->where('occurred_at', '<', $until->format('Y-m-d H:i:s'));
        $row = $query->toBase()
            ->selectRaw('COUNT(*) AS events')
            ->selectRaw("COUNT(CASE WHEN type = 'request' THEN 1 END) AS requests")
            ->selectRaw("COUNT(CASE WHEN type = 'request' AND duration_ms >= 0 THEN 1 END) AS timed")
            ->selectRaw("AVG(CASE WHEN type = 'request' AND duration_ms >= 0 THEN duration_ms END) AS duration")
            ->selectRaw("COUNT(CASE WHEN type = 'request' AND (status_code BETWEEN 500 AND 599 OR severity IN ('error', 'critical')) THEN 1 END) AS failed")
            ->selectRaw("COUNT(CASE WHEN type = 'exception' THEN 1 END) AS exceptions")
            ->selectRaw("COUNT(CASE WHEN type = 'log' AND severity IN ('error', 'critical') THEN 1 END) AS error_logs")->first();

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

    /** @return Builder<TelemetryEvent> */
    private function events(AlertRule $rule): Builder
    {
        return TelemetryEvent::query()->where('environment_id', $rule->environment_id)
            ->when($rule->service !== null, fn (Builder $query): Builder => $query->where('service', $rule->service));
    }
}
