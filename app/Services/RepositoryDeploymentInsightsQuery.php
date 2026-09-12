<?php

namespace App\Services;

use App\Models\Build;
use App\Models\Repository;

class RepositoryDeploymentInsightsQuery
{
    /**
     * Summarize repository outcomes and the bounded set of recent timed deployments.
     *
     * @param  Repository  $repository  Repository whose deployment history is being summarized.
     * @return array{total: int, succeeded: int, failed: int, success_rate: ?int, median_duration_seconds: ?int, duration_sample_size: int}
     */
    public function metrics(Repository $repository): array
    {
        $counts = $repository->builds()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count);
        $succeeded = $counts->get(Build::STATUS_SUCCEEDED, 0);
        $failed = $counts->get(Build::STATUS_FAILED, 0);
        $completed = $succeeded + $failed;
        $durations = $repository->builds()
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'started_at', 'finished_at'])
            ->map(fn (Build $build): ?int => $build->durationSeconds())
            ->filter(fn (?int $duration): bool => $duration !== null)
            ->sort()
            ->values();
        $durationCount = $durations->count();
        $middle = intdiv($durationCount, 2);
        $median = match (true) {
            $durationCount === 0 => null,
            $durationCount % 2 === 1 => $durations[$middle],
            default => intdiv($durations[$middle - 1] + $durations[$middle], 2),
        };

        return [
            'total' => (int) $counts->sum(),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'success_rate' => $completed > 0 ? (int) round(($succeeded / $completed) * 100) : null,
            'median_duration_seconds' => $median,
            'duration_sample_size' => $durationCount,
        ];
    }
}
