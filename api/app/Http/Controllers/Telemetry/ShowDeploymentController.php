<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchReleasesRequest;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\ReleaseMetricsQuery;
use App\Services\Deploy\DeploymentMarkers;
use App\Services\Monitoring\TelemetryRedactor;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowDeploymentController
{
    /**
     * Show a deployment, comparing its service's telemetry before and after it went live (`?window=` minutes).
     *
     * @param  SearchReleasesRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Deployment  $deployment
     * @param  ProjectOverviewQuery  $overview
     * @param  ReleaseMetricsQuery  $metrics
     * @param  TelemetryRedactor  $redactor
     * @return JsonResponse
     */
    public function __invoke(SearchReleasesRequest $request, #[CurrentUser] User $user, Project $project, Deployment $deployment, ProjectOverviewQuery $overview, ReleaseMetricsQuery $metrics, TelemetryRedactor $redactor): JsonResponse
    {
        $filters = $request->filters();

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'deployment' => [
                'id' => $deployment->id,
                'releaseId' => $deployment->release_id,
                'version' => $deployment->release->version,
                'service' => $deployment->release->serviceLabel(),
                'environmentId' => $deployment->environment_id,
                'environment' => $deployment->environment->name,
                'deployedAt' => $deployment->deployed_at->toIso8601String(),
                'actor' => $deployment->actor?->name,
                'commit' => $deployment->commit_sha,
                'note' => $redactor->redact(['note' => $deployment->note])['note'],
                'buildId' => DeploymentMarkers::buildIdOf($deployment),
            ],
            'filters' => $filters,
            'comparison' => $metrics->aroundDeployment($project, $deployment, (int) $filters['window']),
            'windows' => array_map(fn (int $minutes, string $label): array => ['value' => (string) $minutes, 'label' => __(':window before and after', ['window' => __($label)])], array_keys(SearchReleasesRequest::WINDOWS), SearchReleasesRequest::WINDOWS),
        ]);
    }
}
