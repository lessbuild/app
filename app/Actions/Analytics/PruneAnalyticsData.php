<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\Account;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsExport;
use App\Models\AnalyticsIngestionBatch;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use App\Services\Billing\Entitlements;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

final class PruneAnalyticsData
{
    /**
     * Create a new PruneAnalyticsData instance.
     *
     * Prunes analytics data by each account's retention.
     *
     * @param  Entitlements  $entitlements  Reads each account's event retention.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Drop raw events and visits past their retention, old daily reports and expired exports. Each account keeps events
     * as long as its Analytics plan says (`analytics.retention.days`); a number of days overrides that for everyone.
     *
     * @param  int|null  $days
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

        [$events, $visits] = [0, 0];
        foreach ($this->cutoffs($days) as [$sites, $siteCutoff]) {
            $events += AnalyticsEvent::query()->whereIn('site_id', $sites)->where('occurred_at', '<', $siteCutoff)->delete();
            $visits += AnalyticsVisit::query()->whereIn('site_id', $sites)->where('last_seen_at', '<', $siteCutoff)->delete();
        }

        return [
            'events' => $events,
            'visits' => $visits,
            'batches' => AnalyticsIngestionBatch::query()->where('status', 'processed')->where('processed_at', '<', $cutoff)->delete(),
            'aggregates' => AnalyticsDailyAggregate::query()->where('local_date', '<', $aggregateCutoff)->delete(),
            'exports' => $exports,
        ];
    }

    /**
     * Pair each account's sites with the time before which their events go: the override for everyone, or each
     * account's plan retention (the configured default when the plan doesn't say).
     *
     * @param  int|null  $days
     * @return list<array{list<int>, CarbonInterface}>
     */
    private function cutoffs(?int $days): array
    {
        $sites = AnalyticsSite::query()->join('projects', 'projects.id', '=', 'analytics_sites.project_id')->get(['analytics_sites.id', 'projects.account_id'])
            ->groupBy('account_id');
        $pairs = [];
        foreach ($sites as $accountId => $accountSites) {
            $account = Account::query()->find($accountId);
            $retention = $days ?? ($account === null ? null : $this->entitlements->for($account)->limit('analytics.retention.days')) ?? (int) config('analytics.event_retention_days');
            $pairs[] = [array_values(array_map('intval', $accountSites->pluck('id')->all())), now()->subDays(max(1, $retention))];
        }

        return $pairs;
    }
}
