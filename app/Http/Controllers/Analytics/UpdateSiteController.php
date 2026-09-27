<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveSite;
use App\Http\Requests\Analytics\SiteRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateSiteController
{
    public function __invoke(SiteRequest $request, #[CurrentUser] User $user, Project $project, string $site, ProjectSitesQuery $sites, SaveSite $save): RedirectResponse
    {
        $save->handle($user, $project, $request->toDetails(), $sites->find($project, $site));

        return to_route('analytics.sites.show', [$project, $site])->with('status', __('Site saved.'));
    }
}
