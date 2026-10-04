<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Deploy\DeploymentMarkers;
use App\Services\Deploy\RepositoryDeploymentPlan;
use App\Support\Deploy\ReleaseNotes;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/builds/{build}`. */
final class ShowBuildController
{
    /**
     * Return a deploy: its status, commit and log, where it came from (a redeploy, rollback or promotion), release
     * notes, destructive migrations waiting for approval, the release analysis, its stages, where it can be promoted
     * to, and what the person may do with it.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  ProjectOverviewQuery  $overview
     * @param  RepositoryDeploymentPlan  $plan
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Build $build, ProjectOverviewQuery $overview, RepositoryDeploymentPlan $plan): JsonResponse
    {
        $build->load(['repository.provider', 'website', 'environment', 'requester', 'rolledBackFrom', 'redeployedFrom', 'promotedFrom.environment', 'promotions.environment']);
        $telemetry = $build->environment_id === null ? null
            : Deployment::query()->where('environment_id', $build->environment_id)->where('deployment_key', DeploymentMarkers::keyFor($build->id))->first();
        $targets = $build->status === Build::STATUS_SUCCEEDED && $build->environment !== null
            ? $project->environments()->whereHas('repositories', fn ($query) => $query->where('url', $build->repository->url))->get()
                ->filter(fn (Environment $environment): bool => $environment->kind->rank() > $build->environment->kind->rank())->values()
            : collect();

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'build' => [
                'id' => $build->id,
                'status' => $build->status,
                'active' => $build->isActive(),
                'repository' => ['id' => $build->repository->id, 'name' => $build->repository->name],
                'website' => $build->website->name,
                'revision' => $build->revision,
                'shortRevision' => $build->shortRevision(),
                'revisionUrl' => $build->revision !== null ? $build->repository->revisionUrl($build->revision) : null,
                'ref' => $build->git_ref,
                'commitMessage' => $build->commit_message,
                'requester' => $build->requester?->name,
                'trigger' => $build->trigger_source,
                'startedAt' => $build->started_at?->toIso8601String(),
                'finishedAt' => $build->finished_at?->toIso8601String(),
                'releaseName' => $build->release_name,
                'setupStage' => $build->setup_stage,
                'failureMessage' => $build->failure_message,
                'approvalNote' => $build->approval_note,
                'rolledBackFrom' => $build->rolledBackFrom?->id,
                'redeployedFrom' => $build->redeployedFrom?->id,
                'promotedFrom' => $build->promotedFrom === null ? null : ['id' => $build->promotedFrom->id, 'environment' => $build->promotedFrom->environment?->name, 'note' => $build->promotion_note],
                'promotions' => $build->promotions->map(fn (Build $promotion): array => ['id' => $promotion->id, 'environment' => $promotion->environment?->name])->values(),
                'releaseNotes' => ReleaseNotes::sections($build->release_commits ?? []),
                'commitCount' => count($build->release_commits ?? []),
                'destructiveMigrations' => $build->destructive_migrations,
                'observation' => $build->observation_report === null ? null : ['status' => $build->observation_status, 'error' => $build->observation_error, 'before' => $build->observation_report['before'], 'after' => $build->observation_report['after']],
                'log' => $build->log,
            ],
            'stages' => array_map(fn (string $class): string => __($class::TITLE), $plan->scripts()),
            'telemetry' => $telemetry === null ? null : ['id' => $telemetry->id, 'releaseId' => $telemetry->release_id, 'environmentId' => $telemetry->environment_id],
            'promotionTargets' => $targets->map(fn (Environment $environment): array => ['value' => $environment->id, 'label' => $environment->name])->values(),
            'canDeploy' => $user->can('deploy', $build->repository),
            'canApprove' => $user->can('approve', $build),
        ]);
    }
}
