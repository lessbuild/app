<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Analytics\Concerns\ReadsReportParameters;
use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ShowReportController
{
    use ReadsReportParameters;

    /**
     * Return a site's report as numbers (`GET /api/v1/analytics/sites/{site}/report`): the headline metrics, the
     * pageview series, rankings, goals and page speed, for today or the last 7, 30, 90 or 365 days, with the same
     * filters as the dashboard.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  AnalyticsSite  $site
     * @param  AnalyticsReportQuery  $report
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, AnalyticsSite $site, AnalyticsReportQuery $report): JsonResponse
    {
        $account = $request->attributes->get('account');
        abort_unless($account instanceof Account && $site->project->account_id === $account->id && $user->can('view', $site), 404);
        $period = $this->reportPeriod($request, $site);
        $filters = $this->reportFilters($request);
        $summary = $report->handle($site, $period, $filters);
        $metrics = [];
        foreach ($summary['metrics'] as $metric) {
            $metrics[Str::snake($metric['label'])] = ['value' => $metric['raw'] ?? null, 'change' => $metric['change']];
        }
        $lists = [];
        foreach (['pages', 'entryPages', 'exitPages', 'sources', 'countries', 'campaigns', 'devices', 'browsers', 'operatingSystems', 'outboundLinks', 'fileDownloads', 'notFound', 'channels', 'regions', 'cities', 'screenSizes', 'browserVersions', 'osVersions', 'terms', 'contents', 'engagement'] as $key) {
            $lists[Str::snake($key)] = $summary[$key] ?? [];
        }

        return response()->json(['data' => [
            'site_id' => $site->id,
            'period' => ['days' => $period->days, 'compare' => $period->compare, 'start' => $summary['range']['start']->toIso8601String(), 'end' => $summary['range']['end']->toIso8601String(), 'timezone' => $site->timezone],
            'filters' => array_filter($filters),
            'metrics' => $metrics,
            'series' => ['granularity' => $summary['granularity'] ?? 'day', 'points' => $summary['series']],
            ...$lists,
            'goals' => $summary['goals'],
            'page_speed' => $summary['vitals'] ?? null,
        ]]);
    }
}
