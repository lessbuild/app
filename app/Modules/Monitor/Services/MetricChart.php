<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\OtlpTimestamp;
use App\Modules\Monitor\Models\MetricSample;
use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Services\Telemetry\MetricProjection;
use Carbon\CarbonImmutable;

final class MetricChart
{
    public const LIMIT = 600;

    public function __construct(private readonly MetricAnomalyDetector $anomalies) {}

    /** @return array<string, mixed> */
    public function read(MetricSeries $series, CarbonImmutable $from, CarbonImmutable $until, string $mode): array
    {
        $samples = $series->samples()->where('time_key', '>=', MetricProjection::timeKey($from))
            ->where('time_key', '<', MetricProjection::timeKey($until))
            ->orderByDesc('time_key')->limit(self::LIMIT + 1)->get()->reverse()->values();
        $truncated = $samples->count() > self::LIMIT;
        $previous = $truncated ? $samples->shift() : null;
        $points = [];
        foreach ($samples as $sample) {
            $points[] = [
                ...$this->reading($series, $sample, $previous, $mode),
                'time' => $sample->occurred_at->toISOString(), 'time_key' => $sample->time_key,
                'event_id' => $sample->telemetry_event_id,
                'x' => round(max(0, min(1000, $from->diffInMicroseconds($sample->occurred_at) / max(1, $from->diffInMicroseconds($until)) * 1000)), 3),
            ];
            $previous = $sample;
        }
        $values = array_column(array_filter($points, fn (array $point): bool => $point['value'] !== null), 'value');
        $minimum = $values === [] ? null : min($values);
        $maximum = $values === [] ? null : max($values);
        foreach ($points as &$point) {
            $point['y'] = $point['value'] === null ? null : ($maximum === $minimum ? 100.0 : round(180 - ($point['value'] - $minimum) / ($maximum - $minimum) * 160, 3));
        }
        unset($point);
        $anomalySummary = $this->anomalies->annotate($points);
        $points = $anomalySummary['points'];

        return [
            'points' => $points, 'truncated' => $truncated, 'minimum' => $minimum, 'maximum' => $maximum,
            'latest' => $points === [] ? null : $points[array_key_last($points)], 'valid' => count($values),
            'anomalies' => $anomalySummary['count'], 'max_anomaly_score' => $anomalySummary['max_score'],
            'anomaly_baseline_points' => $anomalySummary['baseline_points'],
        ];
    }

    /** @return array{value: float|null, state: string} */
    public function reading(MetricSeries $series, MetricSample $sample, ?MetricSample $previous, string $mode): array
    {
        if ($sample->state !== 'valid' || $sample->value === null) {
            return ['value' => null, 'state' => $sample->state];
        }
        if ($mode === 'value') {
            return ['value' => $sample->value, 'state' => 'valid'];
        }
        if (! $series->supportsRate() || $sample->value < 0) {
            return ['value' => null, 'state' => 'unsupported_rate'];
        }
        if ($series->temporality === 'delta') {
            $startKey = $sample->start_time_key;
            if ($startKey === null || ($previous !== null && strcmp($startKey, $previous->time_key) < 0)) {
                return ['value' => null, 'state' => 'unknown_interval'];
            }
            $difference = $sample->value;
        } else {
            if ($previous === null || $previous->state !== 'valid' || $previous->value === null) {
                return ['value' => null, 'state' => 'missing_baseline'];
            }
            if ($sample->start_time_key !== $previous->start_time_key || $sample->value < $previous->value) {
                return ['value' => null, 'state' => 'counter_reset'];
            }
            $startKey = $previous->time_key;
            $difference = $sample->value - $previous->value;
        }
        $seconds = OtlpTimestamp::fromUnixNano($startKey)->millisecondsUntil(OtlpTimestamp::fromUnixNano($sample->time_key)) / 1000;
        $value = $seconds > 0 ? $difference / $seconds : null;

        return $value !== null && is_finite($value)
            ? ['value' => $value, 'state' => 'valid']
            : ['value' => null, 'state' => 'unknown_interval'];
    }
}
