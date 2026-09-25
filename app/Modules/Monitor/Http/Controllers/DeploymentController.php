<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\SearchReleasesRequest;
use App\Modules\Monitor\Http\Requests\StoreDeploymentRequest;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Services\Core\DeploymentTrafficContext;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\RecordDeployment;
use App\Modules\Monitor\Services\ReleaseMetrics;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DeploymentController extends Controller
{
    public function index(SearchReleasesRequest $request, Application $application, Environment $environment): Response
    {
        $filters = $request->filters();
        $deployments = $environment->deployments()->visibleTo($request->user(), $environment->application->workspace)->with('release')->latest('deployed_at')->latest('id')
            ->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->view('monitor::deployments.index', compact('application', 'environment', 'deployments'))->header('Cache-Control', 'private, no-store');
    }

    public function create(Application $application, Environment $environment): Response
    {
        Gate::authorize('create', [Deployment::class, $environment]);

        return response()->view('monitor::deployments.create', [
            'application' => $application, 'environment' => $environment, 'deploymentId' => (string) Str::uuid(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreDeploymentRequest $request, Application $application, Environment $environment, RecordDeployment $recorder): RedirectResponse
    {
        $deployment = $recorder->record($environment, $request->validated(), actor: $request->user());

        return to_route('monitor.deployments.show', [$application, $environment, $deployment])
            ->with('status', $deployment->wasRecentlyCreated ? 'Deployment recorded. No code was deployed by '.config('app.name').'.' : 'This deployment was already recorded. No duplicate was created.');
    }

    public function show(SearchReleasesRequest $request, Application $application, Environment $environment, Deployment $deployment, CurrentWorkspace $workspace, ReleaseMetrics $metrics, DeploymentTrafficContext $trafficContext, TelemetryRedactor $redactor): Response
    {
        $deployment->load(['release', 'actor:id,name']);
        $filters = $request->filters();
        $requestedMinutes = (int) $filters['window'];
        $requestedSeconds = $requestedMinutes * 60;
        $trafficContexts = $trafficContext->forDeployment($request->user(), $deployment, $requestedSeconds);
        $comparisonSeconds = $trafficContexts->isEmpty()
            ? $requestedSeconds
            : (int) $trafficContexts->min('windowSeconds');

        if ($trafficContexts->contains(fn ($context): bool => $context->windowSeconds !== $comparisonSeconds)) {
            $trafficContexts = $trafficContext->forDeployment($request->user(), $deployment, $comparisonSeconds);
        }

        $comparison = $metrics->aroundDeploymentWindow($workspace->get(), $deployment, $comparisonSeconds, $requestedMinutes, $request->user());
        $nearbyDeployments = $metrics->otherDeploymentsInWindow($workspace->get(), $deployment, $comparison['from'], $comparison['until'], $request->user());
        $overlappingIncidents = $metrics->incidentsOverlappingWindow($workspace->get(), $deployment, $comparison['from'], $comparison['until'], $request->user());

        return response()->view('monitor::deployments.show', [
            ...compact('application', 'environment', 'deployment', 'filters', 'nearbyDeployments', 'overlappingIncidents'),
            'comparison' => $comparison,
            'trafficContexts' => $trafficContexts,
            'note' => $redactor->redact(['note' => $deployment->note])['note'],
            'windowOptions' => SearchReleasesRequest::WINDOWS,
        ])->header('Cache-Control', 'private, no-store');
    }
}
