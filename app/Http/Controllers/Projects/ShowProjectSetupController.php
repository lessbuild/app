<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Projects\ProjectSetupQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowProjectSetupController
{
    /**
     * Show the project's setup guide: each step from connecting a provider to measuring visits, where the project
     * stands on it, and the next thing to do.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSetupQuery  $setup
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSetupQuery $setup): View
    {
        return view('projects.setup', ['overview' => $overview->handle($project, $user), 'setup' => $setup->handle($project), 'canChange' => $user->can('update', $project)]);
    }
}
