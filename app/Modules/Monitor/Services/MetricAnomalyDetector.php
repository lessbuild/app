<?php

namespace App\Modules\Monitor\Services;

final class MetricAnomalyDetector
{
    public const MINIMUM_BASELINE_POINTS = 8;

    public const HISTORY_SIZE = 24;

    public const SCORE_THRESHOLD = 3.5;

    /**
     * @param  list<array<string, mixed>>  $points
     * @return array{points: list<array<string, mixed>>, count: int, max_score: float|null, baseline_points: int}
     */
    public function annotate(array $points, float $threshold = self::SCORE_THRESHOLD): array
    {
        $threshold = max(1, $threshold);
        $history = [];
        $anomalies = 0;
        $maxScore = null;
        $baselinePoints = 0;

        foreach ($points as $index => $point) {
            $value = is_numeric($point['value'] ?? null) ? (float) $point['value'] : null;
            $analysis = $this->analyze($value, $history, $threshold);
            $points[$index]['anomaly'] = $analysis;

            if ($analysis['state'] === 'anomaly') {
                $anomalies++;
                $maxScore = max($maxScore ?? 0, $analysis['score'] ?? 0);
            }
            if ($analysis['baseline'] !== null) {
                $baselinePoints = max($baselinePoints, count($history));
            }

            if ($value !== null) {
                $history[] = $value;
                if (count($history) > self::HISTORY_SIZE) {
                    array_shift($history);
                }
            }
        }

        return [
            'points' => $points,
            'count' => $anomalies,
            'max_score' => $maxScore === null ? null : round($maxScore, 2),
            'baseline_points' => $baselinePoints,
        ];
    }

    /**
     * @param  list<float>  $history
     * @return array{state: string, score: float|null, baseline: float|null, lower: float|null, upper: float|null, direction: string|null}
     */
    private function analyze(?float $value, array $history, float $threshold): array
    {
        if ($value === null) {
            return ['state' => 'unknown', 'score' => null, 'baseline' => null, 'lower' => null, 'upper' => null, 'direction' => null];
        }

        if (count($history) < self::MINIMUM_BASELINE_POINTS) {
            return ['state' => 'warming', 'score' => null, 'baseline' => null, 'lower' => null, 'upper' => null, 'direction' => null];
        }

        $baseline = $this->median($history);
        $deviations = array_map(fn (float $item): float => abs($item - $baseline), $history);
        $mad = $this->median($deviations);
        $mean = array_sum($history) / count($history);
        $variance = array_sum(array_map(fn (float $item): float => ($item - $mean) ** 2, $history)) / count($history);
        $scale = max(1.4826 * $mad, sqrt($variance), abs($baseline) * 0.01, 1e-9);
        $score = abs($value - $baseline) / $scale;
        $margin = $threshold * $scale;

        return [
            'state' => $score >= $threshold ? 'anomaly' : 'normal',
            'score' => round($score, 2),
            'baseline' => round($baseline, 6),
            'lower' => round($baseline - $margin, 6),
            'upper' => round($baseline + $margin, 6),
            'direction' => $value >= $baseline ? 'high' : 'low',
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }
}
