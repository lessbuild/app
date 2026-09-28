<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveServiceLevelObjective;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class ArchiveObjectiveController
{
    /**
     * Archives an SLO.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ServiceLevelObjective  $objective
     * @param  ArchiveServiceLevelObjective  $archive
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ServiceLevelObjective $objective, ArchiveServiceLevelObjective $archive): RedirectResponse
    {
        $archive->handle($objective, $user);

        return to_route('monitoring.objectives', $project)->with('status', __(':objective was archived.', ['objective' => $objective->name]));
    }
}
