<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectProductSummaryProvider;
use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Data\Projects\ProjectProductSnapshotState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Modules\Analytics\Models\ReportDailyAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

final class AnalyticsProjectSummary implements ProjectProductSummaryProvider
{
    public function __construct(private readonly AnalyticsProjectLink $sites) {}

    public function summarize(PlatformUser $user, Project $project): ?ProjectProductSnapshot
    {
        $authorizedSites = $this->sites->accessibleSites($user, $project);

        if ($authorizedSites->isEmpty()) {
            return null;
        }

        $todayUtc = CarbonImmutable::now('UTC')->startOfDay();
        $aggregateRows = ReportDailyAggregate::query()
            ->whereIn('site_id', $authorizedSites->modelKeys())
            ->where('dimension', 'all')
            ->whereBetween('local_date', [$todayUtc->subDays(8)->toDateString(), $todayUtc->addDay()->toDateString()])
            ->get(['site_id', 'local_date', 'pageviews', 'visits', 'updated_at']);
        $pageviews = 0;
        $visits = 0;
        $updatedAt = null;

        foreach ($authorizedSites as $site) {
            $end = CarbonImmutable::now($site->timezone)->toDateString();
            $start = CarbonImmutable::now($site->timezone)->subDays(6)->toDateString();

            foreach ($aggregateRows->where('site_id', $site->getKey()) as $row) {
                $date = CarbonImmutable::parse($row->local_date)->toDateString();
                if ($date < $start || $date > $end) {
                    continue;
                }

                $pageviews += (int) $row->pageviews;
                $visits += (int) $row->visits;

                if ($row->updated_at !== null && ($updatedAt === null || $row->updated_at->greaterThan($updatedAt))) {
                    $updatedAt = $row->updated_at;
                }
            }

            if ($site->last_processed_at !== null && ($updatedAt === null || $site->last_processed_at->greaterThan($updatedAt))) {
                $updatedAt = $site->last_processed_at;
            }
        }

        $state = $pageviews > 0 || $visits > 0
            ? ProjectProductSnapshotState::Current
            : ProjectProductSnapshotState::Empty;
        $detail = $state === ProjectProductSnapshotState::Current
            ? __(':pageviews pageviews · :visits visits in the last 7 days', [
                'pageviews' => number_format($pageviews),
                'visits' => number_format($visits),
            ])
            : __('No processed traffic in the last 7 days.');
        $site = $authorizedSites->first();

        return new ProjectProductSnapshot(
            title: __('Analytics traffic · 7 days'),
            detail: $detail,
            state: $state,
            updatedAt: $updatedAt,
            url: Route::has('analytics.dashboard') ? route('analytics.dashboard', ['site' => $site->getKey()]) : null,
        );
    }
}
