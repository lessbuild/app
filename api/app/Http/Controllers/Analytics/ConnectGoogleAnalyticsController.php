<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Contracts\Analytics\GoogleAnalyticsData;
use App\Models\AnalyticsSite;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ConnectGoogleAnalyticsController
{
    /**
     * Send the person to Google to allow read-only access to their Analytics, remembering which site it's for.
     *
     * @param  Request  $request
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  GoogleAnalyticsData  $google
     * @return RedirectResponse
     */
    public function __invoke(Request $request, Project $project, AnalyticsSite $site, GoogleAnalyticsData $google): RedirectResponse
    {
        if (! $google->configured()) {
            return back()->withErrors(['property' => __('Google sign-in isn’t set up on this platform yet.')]);
        }
        $state = Str::random(40);
        $request->session()->put('google-analytics.connect', ['state' => $state, 'project' => $project->id, 'site' => $site->id]);

        return redirect()->away($google->authorizationUrl($state));
    }
}
