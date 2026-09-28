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

/** The account's public status pages. */
final class ShowStatusPagesController
{
    /**
     * The account's status pages.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  StatusPagesQuery  $pages
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, StatusPagesQuery $pages): View
    {
        return view('monitoring.status-pages', [
            'overview' => $overview->handle($project, $user),
            'pages' => $pages->handle($project->account_id),
            'canManage' => $user->can('create', [StatusPage::class, $project]),
        ]);
    }
}
