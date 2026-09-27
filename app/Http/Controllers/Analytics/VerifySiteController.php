<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\VerifySite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class VerifySiteController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $site, ProjectSitesQuery $sites, VerifySite $verify): RedirectResponse
    {
        return $verify->handle($user, $sites->find($project, $site))
            ? to_route('analytics.sites.show', [$project, $site])->with('status', __('Verified. Data starts appearing as soon as visitors arrive.'))
            : to_route('analytics.sites.show', [$project, $site])->with('notice', __('None of this site’s hostnames is a verified domain of the project yet. Verify one under Domains, then check again.'));
    }
}
