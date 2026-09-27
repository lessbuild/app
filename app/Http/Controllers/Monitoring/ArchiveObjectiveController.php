<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveServiceLevelObjective;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class ArchiveObjectiveController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $objective, ProjectAlertRulesQuery $rules, ArchiveServiceLevelObjective $archive): RedirectResponse
    {
        $target = $rules->objective($project, $objective);
        $archive->handle($target, $user);

        return to_route('monitoring.objectives', $project)->with('status', __(':objective was archived.', ['objective' => $target->name]));
    }
}
