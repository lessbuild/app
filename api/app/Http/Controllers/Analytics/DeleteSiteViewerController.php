<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\RemoveSiteViewer;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsSiteViewer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteSiteViewerController
{
    /**
     * Remove someone's view-only access and return to the site's settings. Another site's viewers are a 404.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  int  $viewer
     * @param  RemoveSiteViewer  $remove
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, int $viewer, RemoveSiteViewer $remove): JsonResponse
    {
        $remove->handle($user, AnalyticsSiteViewer::query()->where('site_id', $site->id)->findOrFail($viewer));

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site->id], false), 'message' => __('Access removed.')]);
    }
}
