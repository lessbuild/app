<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\Admin\BusinessAnalytics;
use Illuminate\Contracts\View\View;

final class ShowBusinessAnalyticsController
{
    /**
     * Show the business summary: totals, estimated revenue, each service's tier mix and the 30-day trend.
     *
     * @param  BusinessAnalytics  $analytics
     * @return View
     */
    public function __invoke(BusinessAnalytics $analytics): View
    {
        return view('admin.analytics', $analytics->snapshot());
    }
}
