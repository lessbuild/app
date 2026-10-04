<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Contracts\Analytics\GoogleAnalyticsData;
use App\Models\AnalyticsSite;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConnectGoogleAnalyticsController
{
    /**
     * Send the person to Google to allow read-only access to their Analytics, remembering which site it's for.
     *
     * @param  Request  $request
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  GoogleAnalyticsData  $google
     * @return JsonResponse
     */
    public function __invoke(Request $request, Project $project, AnalyticsSite $site, GoogleAnalyticsData $google): JsonResponse
    {
        if (! $google->configured()) {
            throw ValidationException::withMessages(['property' => __('Google sign-in isn’t set up on this platform yet.')]);
        }
        $state = Str::random(40);
        $request->session()->put('google-analytics.connect', ['state' => $state, 'project' => $project->id, 'site' => $site->id]);

        return response()->json(['redirect' => $google->authorizationUrl($state)]);
    }
}
