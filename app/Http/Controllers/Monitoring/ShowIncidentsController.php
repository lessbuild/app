<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectIncidentsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowIncidentsController
{
    /**
     * Show the project's open incidents, or resolved ones with `?status=resolved`.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectIncidentsQuery  $incidents
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectIncidentsQuery $incidents): View
    {
        $status = in_array($request->query('status'), ['open', 'resolved'], true) ? (string) $request->query('status') : 'open';

        return view('monitoring.incidents', [
            'overview' => $overview->handle($project, $user),
            'incidents' => $incidents->handle($project, $status),
            'status' => $status,
        ]);
    }
}
