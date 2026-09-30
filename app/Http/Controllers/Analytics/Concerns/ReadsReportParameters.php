<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics\Concerns;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsSite;
use Illuminate\Http\Request;

/** Reads a report's period and filters from the query string, the same way everywhere a report is shown. */
trait ReadsReportParameters
{
    /**
     * Get the period asked for: custom dates (`from` and `to`, Y-m-d) when both are given and valid, otherwise a
     * preset number of days; compared as `compare` says (previous period, same period last year, or none).
     *
     * @param  Request  $request
     * @param  AnalyticsSite  $site
     * @return ReportPeriod
     */
    private function reportPeriod(Request $request, AnalyticsSite $site): ReportPeriod
    {
        $compare = $request->string('compare', 'previous')->toString();
        if ($request->filled(['from', 'to'])) {
            $custom = ReportPeriod::between($site->timezone, $request->string('from')->toString(), $request->string('to')->toString(), $compare);
            if ($custom !== null) {
                return $custom;
            }
        }

        return ReportPeriod::lastDays($site->timezone, $this->reportDays($request), $compare);
    }

    /**
     * Get the period asked for: today (1) or 7, 30, 90 or 365 days, else 30.
     *
     * @param  Request  $request
     * @return int
     */
    private function reportDays(Request $request): int
    {
        return in_array((int) $request->query('days'), [1, 7, 30, 90, 365], true) ? (int) $request->query('days') : 30;
    }

    /**
     * Get the report's filters (page, source, campaign, device, country), trimmed and limited in length.
     *
     * @param  Request  $request
     * @return array<string, string|null>
     */
    private function reportFilters(Request $request): array
    {
        $filters = [];
        foreach (['path' => 2048, 'source' => 255, 'campaign' => 150, 'device' => 32, 'browser' => 64, 'os' => 64, 'channel' => 32, 'region' => 100, 'city' => 100, 'screen' => 16, 'term' => 150, 'content' => 150] as $key => $max) {
            $value = trim($request->string($key)->toString());
            $filters[$key] = $value !== '' ? mb_substr($value, 0, $max) : null;
        }
        $country = strtoupper(trim($request->string('country')->toString()));
        $filters['country'] = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;

        return $filters;
    }
}
