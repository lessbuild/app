<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Analytics\Concerns\ReadsReportParameters;
use App\Models\AnalyticsSiteViewer;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Queries\Analytics\LiveVisitorsQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowViewerReportController
{
    use ReadsReportParameters;

    /**
     * Show a site's report to someone with view-only access, through their personal link. Unknown or revoked links are
     * a 404.
     *
     * @param  Request  $request
     * @param  string  $token
     * @param  AnalyticsReportQuery  $report
     * @param  LiveVisitorsQuery  $live
     * @return View
     */
    public function __invoke(Request $request, string $token, AnalyticsReportQuery $report, LiveVisitorsQuery $live): View
    {
        $viewer = AnalyticsSiteViewer::query()->where('token_hash', hash('sha256', $token))->with('site')->firstOrFail();
        $site = $viewer->site;
        $filters = $this->reportFilters($request);
        if ($request->hasHeader('X-Live-Region')) {
            return view('analytics._live', ['recent' => $live->handle($site, $filters)]);
        }
        $viewer->forceFill(['last_viewed_at' => now()])->save();
        $period = $this->reportPeriod($request, $site);

        return view('analytics.shared', [
            'site' => $site,
            'token' => $token,
            'reportRoute' => 'analytics.viewer',
            'embed' => false,
            'days' => $this->reportDays($request),
            'period' => $period,
            'filters' => $filters,
            'summary' => $report->handle($site, $period, $filters),
            'releases' => collect(),
        ]);
    }
}
