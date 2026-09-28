<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Models\Project;
use App\Models\User;
use App\Queries\Audit\ProjectActivityQuery;
use App\Queries\Projects\ProjectChecklistQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowProjectController
{
    /**
     * The project overview: services, environments, the getting-started checklist and recent activity.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $query
     * @param  ProjectChecklistQuery  $checklist
     * @param  ProjectActivityQuery  $activity
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $query, ProjectChecklistQuery $checklist, ProjectActivityQuery $activity): View
    {
        return view('projects.show', [
            'overview' => $query->handle($project, $user),
            'checklist' => $checklist->handle($project, $user),
            'activity' => $activity->handle($project->id),
            'canViewAuditLog' => $user->can('viewAuditLog', $project->account),
        ]);
    }
}
