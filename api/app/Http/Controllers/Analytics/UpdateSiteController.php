<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveSite;
use App\Http\Requests\Analytics\SiteRequest;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateSiteController
{
    /**
     * Save a site's settings.
     *
     * @param  SiteRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  SaveSite  $save
     * @return JsonResponse
     */
    public function __invoke(SiteRequest $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, SaveSite $save): JsonResponse
    {
        $save->handle($user, $project, $request->toDetails(), $site);

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site], false), 'message' => __('Site saved.')]);
    }
}
