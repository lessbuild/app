<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveServiceLevelObjective;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ArchiveObjectiveController
{
    /**
     * Archive an SLO.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ServiceLevelObjective  $objective
     * @param  ArchiveServiceLevelObjective  $archive
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ServiceLevelObjective $objective, ArchiveServiceLevelObjective $archive): JsonResponse
    {
        $archive->handle($objective, $user);

        return response()->json(['redirect' => route('monitoring.objectives', $project, false), 'message' => __(':objective was archived.', ['objective' => $objective->name])]);
    }
}
