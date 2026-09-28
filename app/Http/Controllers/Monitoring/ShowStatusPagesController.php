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
     * Show the account's status pages, with the monitors the Add a status page modal offers to people who can add one.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  StatusPagesQuery  $pages
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, StatusPagesQuery $pages): View
    {
        $canManage = $user->can('create', [StatusPage::class, $project]);

        return view('monitoring.status-pages', [
            'overview' => $overview->handle($project, $user),
            'pages' => $pages->handle($project->account_id),
            'canManage' => $canManage,
            'monitors' => $canManage ? $pages->monitors($project->account) : collect(),
        ]);
    }
}
