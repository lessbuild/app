<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Deploy\DeploymentMarkers;
use App\Services\Deploy\RepositoryDeploymentPlan;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowBuildController
{
    /**
     * Show a deploy's page: its stages and log, how it relates to other deploys, and what the viewer may do next
     * (deploy again, approve, promote).
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  ProjectOverviewQuery  $overview
     * @param  RepositoryDeploymentPlan  $plan
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Build $build, ProjectOverviewQuery $overview, RepositoryDeploymentPlan $plan): View
    {
        return view('deploy.build', [
            'overview' => $overview->handle($project, $user),
            'build' => $build->load(['repository.provider', 'website', 'environment', 'requester', 'approver', 'rolledBackFrom', 'redeployedFrom', 'promotedFrom.environment', 'promotions.environment']),
            'stages' => array_map(fn (string $class): string => $class::TITLE, $plan->scripts()),
            'telemetryDeployment' => $build->environment_id === null ? null
                : Deployment::query()->where('environment_id', $build->environment_id)->where('deployment_key', DeploymentMarkers::keyFor($build->id))->first(),
            'canDeploy' => $user->can('deploy', $build->repository),
            'canApprove' => $user->can('approve', $build),
            'promotionTargets' => $build->status === Build::STATUS_SUCCEEDED && $build->environment !== null
                ? $project->environments()->whereHas('repositories', fn ($query) => $query->where('url', $build->repository->url))->get()
                    ->filter(fn (\App\Models\Environment $environment): bool => $environment->kind->rank() > $build->environment->kind->rank())->values()
                : collect(),
        ]);
    }
}
