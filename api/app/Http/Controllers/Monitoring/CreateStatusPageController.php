<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\StatusPageForm;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\StatusPagesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class CreateStatusPageController
{
    /**
     * Describe the form for adding a status page.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  StatusPagesQuery  $pages
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, StatusPagesQuery $pages): JsonResponse
    {
        return response()->json(['overview' => $overview->handle($project, $user), 'form' => StatusPageForm::for($pages->monitors($project->account), null)]);
    }
}
