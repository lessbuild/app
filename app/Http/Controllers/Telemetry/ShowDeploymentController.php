<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchReleasesRequest;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\ReleaseMetricsQuery;
use App\Services\Monitoring\TelemetryRedactor;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** Error rate and latency before and after a deployment. */
final class ShowDeploymentController
{
    /**
     * Show a deployment's page comparing equal windows before and after it.
     *
     * @param  SearchReleasesRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Deployment  $deployment
     * @param  ProjectOverviewQuery  $overview
     * @param  ReleaseMetricsQuery  $metrics
     * @param  TelemetryRedactor  $redactor
     * @return View
     */
    public function __invoke(SearchReleasesRequest $request, #[CurrentUser] User $user, Project $project, Deployment $deployment, ProjectOverviewQuery $overview, ReleaseMetricsQuery $metrics, TelemetryRedactor $redactor): View
    {
        $filters = $request->filters();

        return view('telemetry.deployment', [
            'overview' => $overview->handle($project, $user),
            'deployment' => $deployment,
            'filters' => $filters,
            'comparison' => $metrics->aroundDeployment($project, $deployment, (int) $filters['window']),
            'note' => $redactor->redact(['note' => $deployment->note])['note'],
            'windowOptions' => SearchReleasesRequest::WINDOWS,
        ]);
    }
}
