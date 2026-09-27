<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Deploy\RepositoryDeploymentPlan;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowBuildController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Build $build, ProjectOverviewQuery $overview, RepositoryDeploymentPlan $plan): View
    {
        return view('deploy.build', [
            'overview' => $overview->handle($project, $user),
            'build' => $build->load(['repository.provider', 'website', 'environment', 'requester', 'approver', 'rolledBackFrom', 'redeployedFrom']),
            'stages' => array_map(fn (string $class): string => $class::$title, $plan->scripts()),
            'canDeploy' => $user->can('deploy', $build->repository),
            'canApprove' => $user->can('approve', $build),
        ]);
    }
}
