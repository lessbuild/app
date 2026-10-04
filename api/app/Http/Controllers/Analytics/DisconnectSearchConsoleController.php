<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\DisconnectSearchConsole;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DisconnectSearchConsoleController
{
    /**
     * Unlink a site from Google Search Console.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  DisconnectSearchConsole  $disconnect
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, DisconnectSearchConsole $disconnect): JsonResponse
    {
        $disconnect->handle($user, $site);

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site], false), 'message' => __('Search Console is disconnected.')]);
    }
}
