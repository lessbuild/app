<?php

namespace App\Http\Controllers;

use App\Services\BusinessAnalytics;
use Illuminate\Http\Response;

class AdminAnalyticsController extends Controller
{
    /**
     * Require platform administration and render an uncached business analytics snapshot.
     */
    public function __invoke(BusinessAnalytics $analytics): Response
    {
        $this->authorize('platform-admin');

        return response()->view('admin.analytics', $analytics->snapshot())->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
