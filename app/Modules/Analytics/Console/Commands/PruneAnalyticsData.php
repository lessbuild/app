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
use Illuminate\Support\Facades\DB;
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
        $exportWorkspaceIds = ReportExport::query()->where('expires_at', '<', now())
            ->select('workspace_id')->distinct()->orderBy('workspace_id')->pluck('workspace_id');
        foreach ($exportWorkspaceIds as $workspaceId) {
            $workspace = Workspace::query()->find($workspaceId);

            if ($workspace === null) {
                continue;
            }

            $exports += $this->pruneWorkspaceExports(
                $workspace,
                fn (): Builder => ReportExport::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('expires_at', '<', now()),
            );
        }

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
            $counts['exports'] += $this->pruneWorkspaceExports(
                $workspace,
                fn (): Builder => ReportExport::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where(function (Builder $query) use ($exportCutoff, $now): void {
                        $query->where('expires_at', '<', $now);

                        if ($exportCutoff !== null) {
                            $query->orWhere('created_at', '<', $exportCutoff);
                        }
                    }),
            );
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

    /** @param \Closure(): Builder<ReportExport> $queryFactory */
    private function pruneWorkspaceExports(Workspace $workspace, \Closure $queryFactory): int
    {
        return DB::connection('analytics')->transaction(function () use ($workspace, $queryFactory): int {
            DB::connection('analytics')->table('workspaces')
                ->where('id', $workspace->getKey())->update(['id' => DB::raw('id')]);
            $lockedWorkspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->first();

            if ($lockedWorkspace === null) {
                return 0;
            }

            // Accepted site deletion requests take this same workspace lock before
            // creating their operation. This makes the fence check atomic with
            // pruning each export's file and ownership row.
            $pendingSiteIds = DB::connection('analytics')->table('site_deletion_operations')
                ->where('workspace_source_id', (string) $lockedWorkspace->getKey())
                ->where('status', '!=', 'completed')
                ->pluck('site_source_id');
            $exports = $queryFactory();

            if ($pendingSiteIds->isNotEmpty()) {
                $exports->whereNotIn('site_id', $pendingSiteIds);
            }

            if (! $exports->exists()) {
                return 0;
            }

            try {
                $disk = Storage::disk('analytics-local');
                $files = $disk->allFiles('exports');
            } catch (\Throwable) {
                return 0;
            }

            $pruned = 0;
            $exports->chunkById(100, function ($items) use ($disk, $files, &$pruned): void {
                foreach ($items as $export) {
                    $exportId = (string) $export->getKey();
                    $deterministicPath = 'exports/'.$exportId.'.csv';
                    $legacyPattern = '/^exports\/'.preg_quote($exportId, '/').'(?:-[A-Za-z0-9_.-]+|_[A-Za-z0-9_.-]+|\.[A-Za-z0-9_.-]+|\/[^\/]+)$/';
                    $ownsPath = static fn (string $path): bool => $path === $deterministicPath || preg_match($legacyPattern, $path) === 1;
                    $paths = [$deterministicPath];

                    if (filled($export->file_path)) {
                        $persistedPath = (string) $export->file_path;

                        if (! $ownsPath($persistedPath)) {
                            continue;
                        }

                        $paths[] = $persistedPath;
                    }

                    foreach ($files as $candidate) {
                        if (is_string($candidate) && $ownsPath($candidate)) {
                            $paths[] = $candidate;
                        }
                    }

                    $cleanupConfirmed = true;
                    try {
                        foreach (array_unique($paths) as $path) {
                            $disk->delete($path);

                            if ($disk->exists($path)) {
                                $cleanupConfirmed = false;
                                break;
                            }
                        }
                    } catch (\Throwable) {
                        // Keep the row as the durable owner of a file whose removal
                        // could not be confirmed; a later prune can retry it.
                        $cleanupConfirmed = false;
                    }

                    if (! $cleanupConfirmed) {
                        continue;
                    }

                    $export->delete();
                    $pruned++;
                }
            });

            return $pruned;
        }, attempts: 3);
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
