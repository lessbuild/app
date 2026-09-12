<?php

namespace App\Services;

use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderConnectionHistoryQuery
{
    /**
     * Build the filtered connection-check relationship for a provider.
     *
     * @param  array{result: ?string, source: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return HasMany<ProviderConnectionCheck, Provider> The provider-scoped history query.
     */
    public function for(Provider $provider, array $filters): HasMany
    {
        return $provider->connectionChecks()
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
     * Summarize a retained, newest-first set of connection observations.
     *
     * @param  Collection<int, ProviderConnectionCheck>  $checks
     * @return array{total: int, successful: int, success_rate: ?int, median_successful_duration_ms: ?int, failure_streak: int}
     */
    public function metrics(Collection $checks): array
    {
        $total = $checks->count();
        $successful = $checks->where('successful', true)->count();
        $durations = $checks
            ->where('successful', true)
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
            'median_successful_duration_ms' => $medianDuration,
            'failure_streak' => $failureStreak,
        ];
    }

    /**
     * Summarize the newest retained observations matching the history filters.
     *
     * @param  array{result: ?string, source: ?string, date_from: ?string, date_to: ?string}  $filters
     * @return array{total: int, healthy: int, failed: int, success_rate: ?int, median_successful_duration_ms: ?int, latest_at: CarbonInterface|null}
     */
    public function filteredMetrics(Provider $provider, array $filters): array
    {
        $checks = $this->for($provider, $filters)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(ProviderConnectionCheck::MAX_PER_PROVIDER)
            ->get(['id', 'successful', 'duration_ms', 'checked_at']);
        $summary = $this->metrics($checks);

        return [
            'total' => $summary['total'],
            'healthy' => $summary['successful'],
            'failed' => $summary['total'] - $summary['successful'],
            'success_rate' => $summary['success_rate'],
            'median_successful_duration_ms' => $summary['median_successful_duration_ms'],
            'latest_at' => $checks->first()?->checked_at,
        ];
    }
}
