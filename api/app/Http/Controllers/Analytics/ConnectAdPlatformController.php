<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Services\Analytics\AdPlatforms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConnectAdPlatformController
{
    /**
     * Send the person to Google or Meta to allow reading their ads reporting, remembering which site it's for.
     *
     * @param  Request  $request
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  string  $platform
     * @param  AdPlatforms  $platforms
     * @return JsonResponse
     */
    public function __invoke(Request $request, Project $project, AnalyticsSite $site, string $platform, AdPlatforms $platforms): JsonResponse
    {
        $client = $platforms->for($platform);
        if (! $client->configured()) {
            throw ValidationException::withMessages(['ads' => __('This ad platform isn’t set up on this installation yet.')]);
        }
        $state = Str::random(40);
        $request->session()->put('ads.connect', ['state' => $state, 'platform' => $platform, 'project' => $project->id, 'site' => $site->id]);

        return response()->json(['redirect' => $client->authorizationUrl($state)]);
    }
}
