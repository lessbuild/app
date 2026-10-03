<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusPage;
use App\Queries\Monitoring\MonthlyUptimeQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

final class ShowStatusPageMonthController
{
    /**
     * Show a published status page's uptime report for one month, from the month it was created up to this one.
     *
     * @param  string  $slug
     * @param  string  $month  YYYY-MM
     * @param  MonthlyUptimeQuery  $uptime
     * @return Response
     */
    public function __invoke(string $slug, string $month, MonthlyUptimeQuery $uptime): Response
    {
        $page = StatusPage::query()->where('slug', $slug)->where('published', true)->with('account')->firstOrFail();
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC');
        $first = CarbonImmutable::parse($page->created_at ?? now())->utc()->startOfMonth();
        abort_if($start === null || $start->greaterThan(CarbonImmutable::now('UTC')) || $start->lessThan($first), 404);
        $months = [];
        for ($cursor = CarbonImmutable::now('UTC')->startOfMonth(); $cursor->greaterThanOrEqualTo($first) && count($months) < 24; $cursor = $cursor->subMonth()) {
            $months[] = $cursor;
        }

        return response()->view('status-pages.month', ['page' => $page, 'report' => $uptime->handle($page, $start), 'months' => $months])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
