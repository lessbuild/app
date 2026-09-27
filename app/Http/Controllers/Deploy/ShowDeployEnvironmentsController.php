<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowDeployEnvironmentsController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): View
    {
        return view('deploy.environments', [
            'overview' => $overview->handle($project, $user),
            'environments' => $project->environments()->withCount(['variables', 'processes', 'resources'])->orderBy('name')->get(),
        ]);
    }
}
