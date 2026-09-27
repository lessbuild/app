<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowGoalsController
{
    /**
     * The goals of the chosen site (or the project's first).
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites): View
    {
        $site = $sites->selected($project, $request->query('site'));

        return view('analytics.goals', [
            'overview' => $overview->handle($project, $user),
            'sites' => $sites->handle($project),
            'site' => $site,
            'goals' => $site?->goals()->latest()->get() ?? collect(),
            'canManage' => $user->can('manageService', [$project, 'analytics']),
        ]);
    }
}
