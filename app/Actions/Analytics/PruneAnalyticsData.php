<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsExport;
use App\Models\AnalyticsIngestionBatch;
use App\Models\AnalyticsVisit;
use Illuminate\Support\Facades\Storage;

final class PruneAnalyticsData
{
    /**
     * Drop raw events and visits past their retention, old daily reports and expired exports.
     *
     * @return array{events: int, visits: int, batches: int, aggregates: int, exports: int}
     */
    public function handle(?int $days = null): array
    {
        $cutoff = now()->subDays(max(1, $days ?? (int) config('analytics.event_retention_days')));
        $aggregateCutoff = now()->subMonths(max(1, (int) config('analytics.aggregate_retention_months')))->toDateString();

        $exports = 0;
        AnalyticsExport::query()->where('expires_at', '<', now())->chunkById(100, function ($items) use (&$exports): void {
            foreach ($items as $export) {
                if ($export->file_path !== null) {
                    Storage::disk('local')->delete($export->file_path);
                }
                $export->delete();
                $exports++;
            }
        });

        return [
            'events' => AnalyticsEvent::query()->where('occurred_at', '<', $cutoff)->delete(),
            'visits' => AnalyticsVisit::query()->where('last_seen_at', '<', $cutoff)->delete(),
            'batches' => AnalyticsIngestionBatch::query()->where('status', 'processed')->where('processed_at', '<', $cutoff)->delete(),
            'aggregates' => AnalyticsDailyAggregate::query()->where('local_date', '<', $aggregateCutoff)->delete(),
            'exports' => $exports,
        ];
    }
}
