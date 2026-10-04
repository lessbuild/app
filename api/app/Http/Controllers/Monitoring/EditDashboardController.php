<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\DashboardForm;
use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class EditDashboardController
{
    /**
     * Describe the form for editing a dashboard, with its current settings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Dashboard  $dashboard
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Dashboard $dashboard, ProjectOverviewQuery $overview): JsonResponse
    {
        return response()->json(['overview' => $overview->handle($project, $user), 'form' => DashboardForm::for($dashboard)]);
    }
}
