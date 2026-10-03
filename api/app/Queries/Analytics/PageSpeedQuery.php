<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use Carbon\CarbonImmutable;

final class PageSpeedQuery
{
    /**
     * The most recent measurements a report reads, so memory stays bounded on busy sites.
     *
     * @var int
     */
    public const SAMPLE = 5000;

    /**
     * Where each metric turns from good to "needs improvement", and from that to poor (Google's Core Web Vitals
     * thresholds; milliseconds except CLS).
     *
     * @var array<string, array{0: int|float, 1: int|float}>
     */
    public const THRESHOLDS = ['lcp' => [2500, 4000], 'inp' => [200, 500], 'cls' => [0.1, 0.25], 'ttfb' => [800, 1800]];

    /**
     * Summarise real visitors' page speed (Web Vitals) over a period: the 75th percentile of each metric with its
     * rating, and the slowest pages by LCP among pages with at least five measurements.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  array<string, string|null>  $filters
     * @return array{samples: int, metrics: array<string, array{value: float|null, rating: string|null}>, slowPages: list<array{path: string, lcp: float, samples: int}>}
     */
    public function handle(AnalyticsSite $site, CarbonImmutable $from, CarbonImmutable $until, array $filters = []): array
    {
        $rows = AnalyticsEvent::query()
            ->where('site_id', $site->id)
            ->countable()
            ->where('type', 'vitals')
            ->whereBetween('occurred_at', [$from, $until])
            ->matchingReportFilters($filters)
            ->orderByDesc('occurred_at')
            ->limit(self::SAMPLE)
            ->get(['path', 'properties']);
        $values = array_fill_keys(array_keys(self::THRESHOLDS), []);
        $lcpByPage = [];
        foreach ($rows as $row) {
            $properties = is_array($row->properties) ? $row->properties : [];
            foreach (array_keys(self::THRESHOLDS) as $metric) {
                if (is_int($properties[$metric] ?? null) || is_float($properties[$metric] ?? null)) {
                    $values[$metric][] = (float) $properties[$metric];
                }
            }
            if (is_int($properties['lcp'] ?? null) || is_float($properties['lcp'] ?? null)) {
                $lcpByPage[$row->path][] = (float) $properties['lcp'];
            }
        }
        $metrics = [];
        foreach ($values as $metric => $list) {
            $value = $this->p75($list);
            $metrics[$metric] = ['value' => $value, 'rating' => $value === null ? null : $this->rating($metric, $value)];
        }
        $slow = [];
        foreach ($lcpByPage as $path => $list) {
            if (count($list) >= 5) {
                $slow[] = ['path' => (string) $path, 'lcp' => (float) $this->p75($list), 'samples' => count($list)];
            }
        }
        usort($slow, fn (array $a, array $b): int => $b['lcp'] <=> $a['lcp']);

        return ['samples' => $rows->count(), 'metrics' => $metrics, 'slowPages' => array_slice($slow, 0, 5)];
    }

    /**
     * Get the 75th percentile of some values (nearest rank), or null when there are none.
     *
     * @param  list<float>  $values
     * @return float|null
     */
    private function p75(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);

        return $values[(int) ceil(0.75 * count($values)) - 1];
    }

    /**
     * Rate a metric's value as good, needs improvement or poor.
     *
     * @param  string  $metric
     * @param  float  $value
     * @return string
     */
    private function rating(string $metric, float $value): string
    {
        [$good, $poor] = self::THRESHOLDS[$metric];

        return match (true) {
            $value <= $good => 'good',
            $value <= $poor => 'needs_improvement',
            default => 'poor',
        };
    }
}
