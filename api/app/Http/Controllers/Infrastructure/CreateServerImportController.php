<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Data\Infrastructure\ServerSummary;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class CreateServerImportController
{
    /**
     * Describe the form for importing a server: the server types and the Ubuntu versions it supports.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'types' => ServerSummary::types(),
            'ubuntuVersions' => (array) config('infrastructure.supported_ubuntu_versions'),
        ]);
    }
}
