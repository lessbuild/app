<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Project;
use App\Models\User;
use App\Queries\Deploy\PreviewsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowPreviewsController
{
    /**
     * Show the project's previews: open ones with their state, expiry and secrets, then recently closed ones with
     * their cleanup.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  PreviewsQuery  $previews
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, PreviewsQuery $previews): View
    {
        return view('deploy.previews', ['overview' => $overview->handle($project, $user), ...$previews->handle($project, $user)]);
    }
}
