<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\DeleteSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteSiteController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $site, ProjectSitesQuery $sites, DeleteSite $delete): RedirectResponse
    {
        $target = $sites->find($project, $site);
        $delete->handle($user, $target);

        return to_route('analytics.sites', $project)->with('status', __(':site and its data were deleted.', ['site' => $target->name]));
    }
}
