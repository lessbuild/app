<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Analytics\Concerns\ReadsReportParameters;
use App\Models\AnalyticsSiteViewer;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Queries\Analytics\LiveVisitorsQuery;
use App\Services\Accounts\AccountBranding;
use App\Support\Analytics\ReportPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowViewerReportController
{
    use ReadsReportParameters;

    /**
     * Show a site's report to someone given view-only access, from their own link, and note when they last looked.
     * With ?live=1 only the "right now" panel is answered.
     *
     * @param  Request  $request
     * @param  string  $token
     * @param  AnalyticsReportQuery  $report
     * @param  LiveVisitorsQuery  $live
     * @param  AccountBranding  $branding
     * @return JsonResponse
     */
    public function __invoke(Request $request, string $token, AnalyticsReportQuery $report, LiveVisitorsQuery $live, AccountBranding $branding): JsonResponse
    {
        $viewer = AnalyticsSiteViewer::query()->where('token_hash', hash('sha256', $token))->with('site')->firstOrFail();
        $site = $viewer->site;
        $filters = $this->reportFilters($request);
        if ($request->boolean('live')) {
            return response()->json(['recent' => ReportPayload::live($live->handle($site, $filters))]);
        }
        $viewer->forceFill(['last_viewed_at' => now()])->save();

        return response()->json([
            'site' => $site->name,
            'locked' => false,
            'embed' => false,
            'filters' => $filters,
            'report' => ReportPayload::from($report->handle($site, $this->reportPeriod($request, $site), $filters), $site),
            'today' => now($site->timezone)->toDateString(),
            'branding' => $branding->for($site->project->account),
        ]);
    }
}
