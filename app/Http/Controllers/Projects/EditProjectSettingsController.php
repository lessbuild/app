<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\EnvironmentKind;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class EditProjectSettingsController
{
    /**
     * The project settings page, offering every environment kind except production.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $query): View
    {

        return view('projects.settings', [
            'overview' => $query->handle($project, $user),
            'kinds' => array_values(array_filter(EnvironmentKind::cases(), fn (EnvironmentKind $kind): bool => $kind !== EnvironmentKind::Production)),
        ]);
    }
}
