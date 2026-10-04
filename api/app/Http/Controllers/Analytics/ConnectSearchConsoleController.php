<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Contracts\Analytics\SearchConsole;
use App\Models\AnalyticsSite;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConnectSearchConsoleController
{
    /**
     * Send the person to Google to allow read-only Search Console access for a site, remembering which site in the
     * session.
     *
     * @param  Request  $request
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  SearchConsole  $searchConsole
     * @return JsonResponse
     */
    public function __invoke(Request $request, Project $project, AnalyticsSite $site, SearchConsole $searchConsole): JsonResponse
    {
        if (! $searchConsole->configured()) {
            throw ValidationException::withMessages(['search_console' => __('Search Console isn’t set up on this platform yet.')]);
        }
        $state = Str::random(40);
        $request->session()->put('search-console.connect', ['state' => $state, 'project' => $project->id, 'site' => $site->id]);

        return response()->json(['redirect' => $searchConsole->authorizationUrl($state)]);
    }
}
