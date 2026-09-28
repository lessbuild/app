<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\VerifySite;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class VerifySiteController
{
    /**
     * Check the site's hostnames against the project's verified domains and says what to do if none matches.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  VerifySite  $verify
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, VerifySite $verify): RedirectResponse
    {
        return $verify->handle($user, $site)
            ? to_route('analytics.sites.show', [$project, $site])->with('status', __('Verified. Data starts appearing as soon as visitors arrive.'))
            : to_route('analytics.sites.show', [$project, $site])->with('notice', __('None of this site’s hostnames is a verified domain of the project yet. Verify one under Domains, then check again.'));
    }
}
