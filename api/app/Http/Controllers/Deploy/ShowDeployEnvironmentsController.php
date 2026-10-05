<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/environments`. */
final class ShowDeployEnvironmentsController
{
    /**
     * Return the project's environments (not previews') with whether deploys are open, their runtime and strategy,
     * how many variables, workers and resources each has, whether it's protected or locked (and why), its deploy
     * window, replicas, last successful deploy and how many regions it runs in.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
    {
        $deploys = Build::query()->where('status', Build::STATUS_SUCCEEDED)->whereIn('environment_id', $project->environments()->pluck('id'))
            ->selectRaw('environment_id, max(finished_at) as finished')->groupBy('environment_id')->pluck('finished', 'environment_id');
        $regions = Repository::query()->where('project_id', $project->id)->whereNotNull('environment_id')->with('website.server')->get()
            ->groupBy('environment_id')->map(fn ($repositories): int => $repositories->map(fn (Repository $repository): ?string => $repository->website->server?->region)->filter()->unique()->count());

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'environments' => $project->environments()->whereDoesntHave('preview')->withCount(['variables', 'processes', 'resources'])->orderBy('name')->get()
                ->map(fn (Environment $environment): array => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'blocked' => $environment->deploymentBlockReason() !== null,
                    'locked' => $environment->deployment_locked_at !== null,
                    'requiresApproval' => (bool) $environment->requires_deployment_approval,
                    'runtime' => $environment->runtime_type,
                    'runtimeVersion' => $environment->runtime_version,
                    'strategy' => $environment->deployment_strategy,
                    'variables' => (int) $environment->variables_count,
                    'processes' => (int) $environment->processes_count,
                    'resources' => (int) $environment->resources_count,
                    'protected' => $environment->protected,
                    'lockReason' => $environment->deployment_locked_at !== null ? ($environment->deployment_lock_reason ?? '') : null,
                    'window' => $environment->deployment_window_days !== null && $environment->deployment_window_start !== null
                        ? ['days' => $environment->deployment_window_days, 'start' => $environment->deployment_window_start, 'end' => $environment->deployment_window_end, 'timezone' => $environment->deployment_window_timezone]
                        : null,
                    'replicas' => ['min' => $environment->minimum_replicas, 'max' => $environment->maximum_replicas, 'autoscale' => $environment->autoscale_enabled],
                    'lastDeployAt' => is_string($finished = $deploys[$environment->id] ?? null) ? CarbonImmutable::parse($finished)->toIso8601String() : null,
                    'regions' => (int) ($regions[$environment->id] ?? 0),
                ])->values(),
        ]);
    }
}
