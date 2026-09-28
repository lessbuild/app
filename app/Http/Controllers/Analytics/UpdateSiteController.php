<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveSite;
use App\Http\Requests\Analytics\SiteRequest;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateSiteController
{
    /**
     * Saves a site's settings.
     *
     * @param  SiteRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  SaveSite  $save
     * @return RedirectResponse
     */
    public function __invoke(SiteRequest $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, SaveSite $save): RedirectResponse
    {
        $save->handle($user, $project, $request->toDetails(), $site);

        return to_route('analytics.sites.show', [$project, $site])->with('status', __('Site saved.'));
    }
}
