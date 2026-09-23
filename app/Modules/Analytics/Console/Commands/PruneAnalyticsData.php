<?php

namespace App\Modules\Analytics\Console\Commands;

use App\Core\Data\Billing\ProductPlanResolution;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Invitation;
use App\Modules\Analytics\Models\ReportDailyAggregate;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Visit;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\AnalyticsPlanAuthority;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class PruneAnalyticsData extends Command
{
    protected $signature = 'analytics:prune {--days= : Override event and visit retention days}';

    protected $description = 'Remove expired analytics detail, invitations, and report exports';

    public function handle(AnalyticsPlanAuthority $plans): int
    {
        $rawOverrideDays = $this->option('days');
        $overrideDays = $rawOverrideDays ? max(1, (int) $rawOverrideDays) : null;

        if ($plans->usesCore()) {
            return $this->pruneByWorkspacePlan($plans, $overrideDays);
        }

        $days = $overrideDays ?? max(1, (int) config('analytics.event_retention_days'));
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

    private function pruneByWorkspacePlan(AnalyticsPlanAuthority $plans, ?int $overrideDays): int
    {
        $now = now();
        $counts = [
            'events' => 0,
            'visits' => 0,
            'batches' => 0,
            'aggregates' => 0,
            'exports' => 0,
            'workspaces_skipped' => 0,
        ];

        foreach (Workspace::query()->orderBy('id')->cursor() as $workspace) {
            $resolution = $plans->resolve($workspace);

            if (! $resolution->available) {
                $counts['workspaces_skipped']++;

                continue;
            }

            $siteIds = $workspace->sites()->pluck('id');

            if ($siteIds->isEmpty()) {
                continue;
            }

            $eventDays = $this->retentionLimit($resolution, 'retention_days', $overrideDays);

            if ($eventDays !== null) {
                $cutoff = $now->copy()->subDays($eventDays);
                $counts['events'] += AnalyticsEvent::query()
                    ->whereIn('site_id', $siteIds)
                    ->where('occurred_at', '<', $cutoff)
                    ->delete();
                $counts['visits'] += Visit::query()
                    ->whereIn('site_id', $siteIds)
                    ->where('last_seen_at', '<', $cutoff)
                    ->delete();
                $counts['batches'] += IngestionBatch::query()
                    ->whereIn('site_id', $siteIds)
                    ->where('status', 'processed')
                    ->where('processed_at', '<', $cutoff)
                    ->delete();
            }

            $aggregateMonths = $this->retentionLimit($resolution, 'aggregate_retention_months');

            if ($aggregateMonths !== null) {
                $aggregateCutoff = $now->copy()->subMonths($aggregateMonths)->toDateString();
                $counts['aggregates'] += ReportDailyAggregate::query()
                    ->whereIn('site_id', $siteIds)
                    ->where('local_date', '<', $aggregateCutoff)
                    ->delete();
            }

            $exportHours = $this->retentionLimit($resolution, 'export_retention_hours');
            $exportCutoff = $exportHours === null ? null : $now->copy()->subHours($exportHours);
            $exports = ReportExport::query()
                ->where('workspace_id', $workspace->getKey())
                ->where(function (Builder $query) use ($exportCutoff, $now): void {
                    $query->where('expires_at', '<', $now);

                    if ($exportCutoff !== null) {
                        $query->orWhere('created_at', '<', $exportCutoff);
                    }
                });

            $exports->chunkById(100, function ($items) use (&$counts): void {
                foreach ($items as $export) {
                    if ($export->file_path) {
                        Storage::disk('analytics-local')->delete($export->file_path);
                    }

                    $export->delete();
                    $counts['exports']++;
                }
            });
        }

        $invitations = Invitation::query()->where('expires_at', '<', $now)->delete();
        $this->info(sprintf(
            'Pruned %d events, %d visits, %d batches, %d aggregates, %d invitations, and %d exports; skipped %d unmapped or unavailable workspaces.',
            $counts['events'],
            $counts['visits'],
            $counts['batches'],
            $counts['aggregates'],
            $invitations,
            $counts['exports'],
            $counts['workspaces_skipped'],
        ));

        return self::SUCCESS;
    }

    private function retentionLimit(ProductPlanResolution $plan, string $limit, ?int $operatorOverride = null): ?int
    {
        $configuredLimit = $plan->limits[$limit] ?? null;

        if (! is_int($configuredLimit) || $configuredLimit < 1) {
            return $operatorOverride;
        }

        return $operatorOverride === null ? $configuredLimit : min($configuredLimit, $operatorOverride);
    }
}
