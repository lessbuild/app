<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics\Concerns;

use Illuminate\Http\Request;

/** Reads a report's period and filters from the query string, the same way everywhere a report is shown. */
trait ReadsReportParameters
{
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
        foreach (['path' => 2048, 'source' => 255, 'campaign' => 150, 'device' => 32] as $key => $max) {
            $value = trim($request->string($key)->toString());
            $filters[$key] = $value !== '' ? mb_substr($value, 0, $max) : null;
        }
        $country = strtoupper(trim($request->string('country')->toString()));
        $filters['country'] = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;

        return $filters;
    }
}
