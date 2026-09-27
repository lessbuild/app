<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowDeployEnvironmentController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Environment $environment, ProjectOverviewQuery $overview): View
    {
        return view('deploy.environment', [
            'overview' => $overview->handle($project, $user),
            'environment' => $environment->load(['variables' => fn ($query) => $query->orderBy('key'), 'processes', 'resources']),
            'blockReason' => $environment->deploymentBlockReason(),
            'canManage' => $user->can('configureDeploy', $environment),
        ]);
    }
}
