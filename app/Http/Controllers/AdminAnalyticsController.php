<?php

namespace App\Http\Controllers;

use App\Services\BusinessAnalytics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminAnalyticsController extends Controller
{
    /**
     * Require platform administration and render an uncached business analytics snapshot.
     */
    public function __invoke(Request $request, BusinessAnalytics $analytics): Response
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);

        return response()->view('admin.analytics', $analytics->snapshot())->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
