<?php

namespace App\Modules\Analytics\Console\Commands;

use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Invitation;
use App\Modules\Analytics\Models\ReportDailyAggregate;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneAnalyticsData extends Command
{
    protected $signature = 'analytics:prune {--days= : Override event and visit retention days}';

    protected $description = 'Remove expired analytics detail, invitations, and report exports';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('analytics.event_retention_days')));
        $cutoff = now()->subDays($days);
        $events = AnalyticsEvent::query()->where('occurred_at', '<', $cutoff)->delete();
        $visits = Visit::query()->where('last_seen_at', '<', $cutoff)->delete();
        $batches = IngestionBatch::query()->where('status', 'processed')->where('processed_at', '<', $cutoff)->delete();
        $aggregateCutoff = now()->subMonths(max(1, (int) config('analytics.aggregate_retention_months')))->toDateString();
        $aggregates = ReportDailyAggregate::query()->where('local_date', '<', $aggregateCutoff)->delete();
        $invitations = Invitation::query()->where('expires_at', '<', now())->delete();
        $exports = 0;
        ReportExport::query()->where('expires_at', '<', now())->chunkById(100, function ($items) use (&$exports): void {
            foreach ($items as $export) {
                if ($export->file_path) {
                    Storage::disk('analytics-local')->delete($export->file_path);
                }
                $export->delete();
                $exports++;
            }
        });

        $this->info("Pruned {$events} events, {$visits} visits, {$batches} batches, {$aggregates} aggregates, {$invitations} invitations, and {$exports} exports.");

        return self::SUCCESS;
    }
}
