<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\MonitorSummary;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\ThirdPartyService;
use App\Models\User;
use App\Queries\Monitoring\ProjectMonitorsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Support\Monitoring\StatusProviders;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowMonitorsController
{
    /**
     * List the project's monitors with their health, and the third-party services it follows.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectMonitorsQuery  $monitors
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectMonitorsQuery $monitors): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'monitors' => array_map(fn (Monitor $monitor): MonitorSummary => MonitorSummary::from($monitor), $monitors->handle($project)),
            'thirdParty' => ThirdPartyService::query()->where('project_id', $project->id)->orderBy('name')->get()->map(fn (ThirdPartyService $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'url' => $service->url,
                'summary' => $service->incident ?? $service->description,
                'affected' => $service->affected ?? [],
                'checkedAt' => $service->checked_at?->toIso8601String(),
                'error' => $service->last_error,
                'tone' => $service->tone(),
                'label' => $service->label(),
            ])->values(),
            'providers' => array_map(fn (array $provider): string => $provider['name'], StatusProviders::LIST),
            'canManage' => $user->can('create', [Monitor::class, $project]),
        ]);
    }
}
