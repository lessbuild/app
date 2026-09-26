<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Services\Telemetry\MetricProjection;
use Carbon\CarbonImmutable;

final class MetricAlertObservation
{
    public function __construct(private readonly MetricChart $charts) {}

    /** @return array{state: string, value: float|null, samples: int, reason: string|null} */
    public function measure(AlertRule $rule, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $series = MetricSeries::query()->where('environment_id', $rule->environment_id)->find($rule->metric_series_id);
        $unknown = ['state' => 'no_data', 'value' => null, 'samples' => 0, 'reason' => 'series_unavailable'];
        if ($series === null || $rule->thresholdValue() === null) {
            return $unknown;
        }
        $query = $series->samples()->where('time_key', '>=', MetricProjection::timeKey($from))
            ->where('time_key', '<', MetricProjection::timeKey($until));
        $totals = (clone $query)->toBase()->selectRaw('COUNT(*) AS total, COUNT(value) AS valid, AVG(value) AS mean, MIN(value) AS minimum, MAX(value) AS maximum')->first();
        $latest = (clone $query)->orderByDesc('time_key')->limit(2)->get();
        $sample = $latest->first();
        $unknown['samples'] = (int) $totals->valid;
        if ($sample === null || (int) $totals->total !== (int) $totals->valid || (int) $totals->valid < $rule->minimum_samples) {
            return [...$unknown, 'reason' => 'insufficient_or_invalid_samples'];
        }
        if ($sample->occurred_at->lessThan($until->subSeconds($rule->freshness_seconds))) {
            return [...$unknown, 'reason' => 'stale'];
        }
        $value = match ($rule->aggregation) {
            'last' => $sample->value,
            'mean' => $totals->mean === null ? null : (float) $totals->mean,
            'min' => $totals->minimum === null ? null : (float) $totals->minimum,
            'max' => $totals->maximum === null ? null : (float) $totals->maximum,
            'rate' => $this->charts->reading($series, $sample, $latest->get(1), 'rate')['value'],
            default => null,
        };
        if ($rule->aggregation === 'rate') {
            $baseline = $series->temporality === 'delta' ? $sample->start_time_key : $latest->get(1)?->time_key;
            $earliest = MetricProjection::timeKey($sample->occurred_at->subSeconds($rule->freshness_seconds)->max($from));
            if ($baseline === null || strcmp($baseline, $earliest) < 0) {
                $value = null;
            }
        }
        if ($value === null) {
            return [...$unknown, 'reason' => 'calculation_unavailable'];
        }
        $breaching = $rule->comparison === 'lte' ? $value <= $rule->thresholdValue() : $value >= $rule->thresholdValue();

        return ['state' => $breaching ? 'breaching' : 'healthy', 'value' => $value, 'samples' => (int) $totals->valid, 'reason' => null];
    }
}
