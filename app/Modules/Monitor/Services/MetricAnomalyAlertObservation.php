<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\MetricSample;
use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Services\Telemetry\MetricProjection;
use Carbon\CarbonImmutable;

final class MetricAnomalyAlertObservation
{
    public function __construct(private readonly MetricAnomalyDetector $detector) {}

    /** @return array{state: string, value: float|null, samples: int, reason: string|null} */
    public function measure(AlertRule $rule, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $series = MetricSeries::query()->where('environment_id', $rule->environment_id)->find($rule->metric_series_id);
        $unknown = ['state' => 'no_data', 'value' => null, 'samples' => 0, 'reason' => 'series_unavailable'];
        $threshold = $rule->thresholdValue();
        if ($series === null || $threshold === null) {
            return $unknown;
        }

        $samples = $series->samples()->where('time_key', '<', MetricProjection::timeKey($until))
            ->orderByDesc('time_key')->limit(MetricAnomalyDetector::HISTORY_SIZE + 100)->get()->reverse()->values();
        $points = $samples->map(fn (MetricSample $sample): array => [
            'value' => $sample->state === 'valid' && $sample->value !== null ? (float) $sample->value : null,
            'occurred_at' => $sample->occurred_at,
        ])->all();
        $analysis = collect($this->detector->annotate($points, $threshold)['points']);
        $current = $analysis->filter(fn (array $point): bool => $point['occurred_at']->greaterThanOrEqualTo($from) && $point['occurred_at']->lessThan($until));
        $validSamples = $current->filter(fn (array $point): bool => $point['value'] !== null)->count();
        $latest = $current->last();
        if ($latest === null || $latest['value'] === null || $validSamples < $rule->minimum_samples) {
            return [...$unknown, 'samples' => $validSamples, 'reason' => 'insufficient_or_invalid_samples'];
        }

        $anomaly = $latest['anomaly'] ?? [];
        if (($anomaly['state'] ?? null) === 'warming') {
            return [...$unknown, 'samples' => $validSamples, 'reason' => 'baseline_unavailable'];
        }
        if (($anomaly['state'] ?? null) !== 'anomaly' || ! is_numeric($anomaly['score'] ?? null)) {
            return ['state' => 'healthy', 'value' => is_numeric($anomaly['score'] ?? null) ? (float) $anomaly['score'] : 0.0, 'samples' => $validSamples, 'reason' => null];
        }

        return ['state' => 'breaching', 'value' => (float) $anomaly['score'], 'samples' => $validSamples, 'reason' => null];
    }
}
