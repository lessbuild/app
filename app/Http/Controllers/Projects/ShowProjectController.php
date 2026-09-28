<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Models\Project;
use App\Models\User;
use App\Queries\Audit\ProjectActivityQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Projects\ProjectSetupQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowProjectController
{
    /**
     * Show the project overview: services, environments, setup progress (until it's done or hidden, for people who can
     * change the project) and recent activity.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $query
     * @param  ProjectSetupQuery  $setup
     * @param  ProjectActivityQuery  $activity
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $query, ProjectSetupQuery $setup, ProjectActivityQuery $activity): View
    {
        return view('projects.show', [
            'overview' => $query->handle($project, $user),
            'setup' => $project->checklist_dismissed_at === null && $user->can('update', $project) ? $setup->handle($project) : null,
            'activity' => $activity->handle($project->id),
            'canViewAuditLog' => $user->can('viewAuditLog', $project->account),
        ]);
    }
}
