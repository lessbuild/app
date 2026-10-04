<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\RemoveAnalyticsImport;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteAnalyticsImportController
{
    /**
     * Remove an import and the history it brought in, then return to the site's settings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  int  $import
     * @param  RemoveAnalyticsImport  $remove
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, int $import, RemoveAnalyticsImport $remove): JsonResponse
    {
        $remove->handle($user, $site->imports()->findOrFail($import));

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site->id], false), 'message' => __('Import removed.')]);
    }
}
