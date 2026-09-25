<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Services\BusinessAnalytics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminAnalyticsController extends Controller
{
    /**
     * Require platform administration and render an uncached business analytics snapshot.
     */
    public function __invoke(Request $request, BusinessAnalytics $analytics): Response
    {
        $this->authorize('platform-admin');
        $coreAdmin = $request->routeIs('core.admin.analytics');

        return response()->view($coreAdmin ? 'core::admin.analytics' : 'admin.analytics', $analytics->snapshot())->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
