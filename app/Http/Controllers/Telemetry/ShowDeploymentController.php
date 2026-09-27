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
    public function __invoke(SearchReleasesRequest $request, #[CurrentUser] User $user, Project $project, string $deployment, ProjectOverviewQuery $overview, ReleaseMetricsQuery $metrics, TelemetryRedactor $redactor): View
    {
        $record = Deployment::query()->whereIn('environment_id', $project->environments()->select('id'))
            ->with(['release', 'environment', 'actor'])->findOrFail((int) $deployment);
        $filters = $request->filters();

        return view('telemetry.deployment', [
            'overview' => $overview->handle($project, $user),
            'deployment' => $record,
            'filters' => $filters,
            'comparison' => $metrics->aroundDeployment($project, $record, (int) $filters['window']),
            'note' => $redactor->redact(['note' => $record->note])['note'],
            'windowOptions' => SearchReleasesRequest::WINDOWS,
        ]);
    }
}
