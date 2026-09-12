<?php

namespace App\Services;

use App\Models\Website;
use App\Models\WebsiteHealthCheck;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteHealthHistoryQuery
{
    /**
     * Build a website-scoped health-check query using the accepted history filters.
     *
     * @param  array{result: ?string, source: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return HasMany<WebsiteHealthCheck, Website> The filtered health-check relationship.
     */
    public function for(Website $website, array $filters): HasMany
    {
        return $website->healthChecks()
            ->when($filters['result'] !== null, fn ($query) => $query
                ->where('successful', $filters['result'] === 'healthy'))
            ->when($filters['source'], fn ($query, string $source) => $query
                ->where('source', $source))
            ->when($filters['date_from'], fn ($query, string $date) => $query
                ->whereDate('checked_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query
                ->whereDate('checked_at', '<=', $date));
    }

    /**
     * Load the newest retained observations used by the website detail page.
     *
     * @return Collection<int, WebsiteHealthCheck>
     */
    public function retained(Website $website): Collection
    {
        return $website->healthChecks()
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(WebsiteHealthCheck::MAX_PER_WEBSITE)
            ->get();
    }

    /**
     * Summarize a retained, newest-first set of health observations.
     *
     * @param  Collection<int, WebsiteHealthCheck>  $checks
     * @return array{total: int, successful: int, success_rate: ?int, median_healthy_duration_ms: ?int, failure_streak: int}
     */
    public function metrics(Collection $checks): array
    {
        $total = $checks->count();
        $successful = $checks->where('successful', true)->count();
        $durations = $checks
            ->filter(fn (WebsiteHealthCheck $check): bool => $check->successful && $check->duration_ms !== null)
            ->pluck('duration_ms')
            ->sort()
            ->values();
        $durationCount = $durations->count();
        $middle = intdiv($durationCount, 2);
        $medianDuration = match (true) {
            $durationCount === 0 => null,
            $durationCount % 2 === 1 => $durations[$middle],
            default => (int) round(($durations[$middle - 1] + $durations[$middle]) / 2),
        };
        $failureStreak = 0;
        foreach ($checks as $check) {
            if ($check->successful) {
                break;
            }

            $failureStreak++;
        }

        return [
            'total' => $total,
            'successful' => $successful,
            'success_rate' => $total > 0 ? (int) round(($successful / $total) * 100) : null,
            'median_healthy_duration_ms' => $medianDuration,
            'failure_streak' => $failureStreak,
        ];
    }

    /**
     * Summarize the newest retained observations matching the history filters.
     *
     * @param  array{result: ?string, source: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return array{total: int, healthy: int, failed: int, success_rate: ?int, median_healthy_duration_ms: ?int, latest_at: CarbonInterface|null}
     */
    public function filteredMetrics(Website $website, array $filters): array
    {
        $checks = $this->for($website, $filters)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(WebsiteHealthCheck::MAX_PER_WEBSITE)
            ->get(['id', 'successful', 'duration_ms', 'checked_at']);
        $summary = $this->metrics($checks);

        return [
            'total' => $summary['total'],
            'healthy' => $summary['successful'],
            'failed' => $summary['total'] - $summary['successful'],
            'success_rate' => $summary['success_rate'],
            'median_healthy_duration_ms' => $summary['median_healthy_duration_ms'],
            'latest_at' => $checks->first()?->checked_at,
        ];
    }
}
