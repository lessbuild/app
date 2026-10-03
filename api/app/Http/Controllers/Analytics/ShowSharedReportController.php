<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Analytics\Concerns\ReadsReportParameters;
use App\Models\AnalyticsSite;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Queries\Analytics\LiveVisitorsQuery;
use App\Support\Analytics\SharedReportAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowSharedReportController
{
    use ReadsReportParameters;

    /**
     * Show a shared site report to anyone with its link, after its password when it has one. The page is read-only,
     * isn't indexed by search engines, and leaves out releases and goal settings. The embed address shows it without
     * the page around it, for an iframe on another site; embedding needs a link without a password.
     *
     * @param  Request  $request
     * @param  string  $token
     * @param  AnalyticsReportQuery  $report
     * @param  LiveVisitorsQuery  $live
     * @return View
     */
    public function __invoke(Request $request, string $token, AnalyticsReportQuery $report, LiveVisitorsQuery $live): View
    {
        $site = AnalyticsSite::query()->where('share_token', $token)->firstOrFail();
        $embed = $request->routeIs('analytics.shared.embed');
        if ($embed && $site->share_password !== null) {
            return view('analytics.shared-locked', ['site' => $site, 'token' => $token, 'embed' => true]);
        }
        if (! SharedReportAccess::unlocked($request, $site)) {
            return view('analytics.shared-locked', ['site' => $site, 'token' => $token]);
        }
        $days = $this->reportDays($request);
        $period = $this->reportPeriod($request, $site);
        $filters = $this->reportFilters($request);
        if ($request->hasHeader('X-Live-Region')) {
            return view('analytics._live', ['recent' => $live->handle($site, $filters)]);
        }

        return view('analytics.shared', [
            'site' => $site,
            'token' => $token,
            'embed' => $embed,
            'reportRoute' => $embed ? 'analytics.shared.embed' : 'analytics.shared',
            'days' => $days,
            'period' => $period,
            'filters' => $filters,
            'summary' => $report->handle($site, $period, $filters),
            'releases' => collect(),
        ]);
    }
}
