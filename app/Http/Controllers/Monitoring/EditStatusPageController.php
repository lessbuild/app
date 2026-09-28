<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use App\Queries\Monitoring\StatusPagesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class EditStatusPageController
{
    /**
     * The status page form, filled in.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  StatusPage  $page
     * @param  ProjectOverviewQuery  $overview
     * @param  StatusPagesQuery  $pages
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, StatusPage $page, ProjectOverviewQuery $overview, StatusPagesQuery $pages): View
    {

        return view('monitoring.status-page-form', [
            'overview' => $overview->handle($project, $user),
            'page' => $page,
            'monitors' => $pages->monitors($project->account),
        ]);
    }
}
