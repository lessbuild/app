<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Analytics\Concerns\ReadsReportParameters;
use App\Models\AnalyticsSite;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Queries\Analytics\LiveVisitorsQuery;
use App\Services\Accounts\AccountBranding;
use App\Support\Analytics\ReportPayload;
use App\Support\Analytics\SharedReportAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowSharedReportController
{
    use ReadsReportParameters;

    /**
     * Show a shared site report to anyone with its link: the report for the period and filters asked for, without
     * releases or goal settings. A password-protected report answers `locked` until its password is entered, and its
     * embedded version (?embed=1) always does, since a frame can't ask for the password. With ?live=1 only the
     * "right now" panel is answered.
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
        $site = AnalyticsSite::query()->where('share_token', $token)->firstOrFail();
        $embed = $request->boolean('embed');
        if (($embed && $site->share_password !== null) || ! SharedReportAccess::unlocked($request, $site)) {
            return response()->json(['site' => $site->name, 'locked' => true, 'embed' => $embed]);
        }
        $filters = $this->reportFilters($request);
        if ($request->boolean('live')) {
            return response()->json(['recent' => ReportPayload::live($live->handle($site, $filters))]);
        }

        return response()->json([
            'site' => $site->name,
            'locked' => false,
            'embed' => $embed,
            'filters' => $filters,
            'report' => ReportPayload::from($report->handle($site, $this->reportPeriod($request, $site), $filters), $site),
            'today' => now($site->timezone)->toDateString(),
            'branding' => $branding->for($site->project->account),
        ]);
    }
}
