<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Data\Analytics\SiteRow;
use App\Http\Controllers\Analytics\Concerns\ReadsReportParameters;
use App\Models\AnalyticsAnnotation;
use App\Models\AnalyticsSite;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Queries\Analytics\LiveVisitorsQuery;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Analytics\SiteReleasesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Support\Analytics\ReportPayload;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowOverviewController
{
    use ReadsReportParameters;

    /**
     * Show a site's report (the one chosen with ?site=, else the first): its numbers for the period with the filters
     * applied, the notes and releases on its chart, and the sites to choose from. With ?live=1 only the "right now"
     * panel is answered, for the page to refresh it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @param  AnalyticsReportQuery  $report
     * @param  SiteReleasesQuery  $releases
     * @param  LiveVisitorsQuery  $live
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites, AnalyticsReportQuery $report, SiteReleasesQuery $releases, LiveVisitorsQuery $live): JsonResponse
    {
        $site = $sites->selected($project, $request->query('site'));
        $page = [
            'overview' => $overview->handle($project, $user),
            'sites' => array_map(SiteRow::from(...), $sites->handle($project)),
            'canManage' => $user->can('manageService', [$project, 'analytics']),
            'retentionDays' => (int) config('analytics.event_retention_days'),
        ];
        if ($site === null) {
            return response()->json([...$page, 'site' => null, 'filters' => $this->reportFilters($request), 'report' => null, 'annotations' => [], 'releases' => [], 'today' => null]);
        }
        $filters = $this->reportFilters($request);
        if ($request->boolean('live')) {
            return response()->json(['recent' => ReportPayload::live($live->handle($site, $filters))]);
        }
        $period = $this->reportPeriod($request, $site);

        return response()->json([
            ...$page,
            'site' => SiteRow::from($site),
            'filters' => $filters,
            'report' => ReportPayload::from($report->handle($site, $period, $filters), $site),
            'annotations' => $this->annotations($site, $period->start->toDateString(), $period->end->toDateString()),
            'releases' => $releases->handle($site, $period->start, $period->end)->map(fn (Deployment $deployment): array => [
                'id' => $deployment->id,
                'version' => $deployment->release->version,
                'environment' => $deployment->environment->name,
                'deployedAt' => $deployment->deployed_at->toIso8601String(),
                'fromDeploy' => $deployment->source === 'deploy',
            ])->values(),
            'today' => now($site->timezone)->toDateString(),
        ]);
    }

    /**
     * Get the notes on the site's chart between two days.
     *
     * @param  AnalyticsSite  $site
     * @param  string  $from  Y-m-d.
     * @param  string  $until  Y-m-d.
     * @return array<int, array{id: int, date: string, text: string}>
     */
    private function annotations(AnalyticsSite $site, string $from, string $until): array
    {
        return AnalyticsAnnotation::query()->where('site_id', $site->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $until)->orderBy('date')->get()
            ->map(fn (AnalyticsAnnotation $note): array => ['id' => $note->id, 'date' => $note->date->toDateString(), 'text' => $note->text])->values()->all();
    }
}
