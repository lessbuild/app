<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\SearchIncidentsRequest;
use App\Modules\Monitor\Http\Requests\UpdateIncidentRequest;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\IncidentActivity;
use App\Modules\Monitor\Services\ChangeIncident;
use App\Modules\Monitor\Services\Core\IncidentTrafficContext;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\IncidentDeploymentContext;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class IncidentController extends Controller
{
    public function index(SearchIncidentsRequest $request, CurrentWorkspace $currentWorkspace, TelemetryRedactor $redactor): Response
    {
        $workspace = $currentWorkspace->get();
        $filters = $request->validated();
        $status = $filters['status'] ?? 'active';
        $query = Incident::forWorkspace($workspace)->visibleTo(request()->user(), $workspace)->with(['alertRule.environment.application', 'monitor.environment.application', 'assignee:id,name']);
        $selectedRule = isset($filters['rule']) ? AlertRule::withTrashed()->forWorkspace($workspace)->visibleTo(request()->user(), $workspace)->findOrFail($filters['rule']) : null;
        if ($selectedRule !== null) {
            $query->where('alert_rule_id', $selectedRule->id);
            $selectedRule->forceFill($redactor->redact($selectedRule->only('name')));
        }
        if ($status === 'active') {
            $query->where('active_slot', true);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $incidents = $query->latest('opened_at')->latest('id')->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($request->safe()->except('page'));
        $incidents->each(fn (Incident $incident): Incident => $incident->forceFill($redactor->redact($incident->only('title'))));
        $totals = Incident::forWorkspace($workspace)->visibleTo(request()->user(), $workspace)->toBase()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return response()->view('monitor::incidents.index', compact('incidents', 'status', 'selectedRule', 'totals'))->header('Cache-Control', 'private, no-store');
    }

    public function show(SearchIncidentsRequest $request, Incident $incident, CurrentWorkspace $currentWorkspace, TelemetryRedactor $redactor, IncidentDeploymentContext $deploymentContext, IncidentTrafficContext $trafficContext, WorkspacePlanLimits $limits): Response
    {
        $workspace = $currentWorkspace->get();
        $incident->load(['alertRule.environment.application', 'monitor.environment.application', 'acknowledgedBy:id,name', 'assignee:id,name']);
        $snapshot = $incident->rule_snapshot;
        $snapshot = array_replace($snapshot, $redactor->redact(array_intersect_key($snapshot, ['name' => true, 'service' => true])));
        $incident->forceFill([...$redactor->redact($incident->only('title')), 'rule_snapshot' => $snapshot]);
        $activities = $incident->activities()->with('actor:id,name')->latest('id')
            ->paginate(25, ['*'], 'page', (int) ($request->validated('page') ?? 1));
        $activities->each(fn (IncidentActivity $activity): IncidentActivity => $activity->forceFill($redactor->redact($activity->only('note'))));
        $recentDeployments = $deploymentContext->recent($workspace, $incident, $request->user());
        $analyticsTrafficContexts = $trafficContext->forIncident($request->user(), $incident);

        return response()->view('monitor::incidents.show', [
            'incident' => $incident,
            'activities' => $activities,
            'recentDeployments' => $recentDeployments,
            'analyticsTrafficContexts' => $analyticsTrafficContexts,
            'deploymentContextMinutes' => $limits->deploymentContextMinutes($workspace),
            'assignees' => $workspace->members()->wherePivotIn('role', ['owner', 'admin', 'member'])
                ->select(['users.id', 'users.name'])->orderBy('users.name')->orderBy('users.id')->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(UpdateIncidentRequest $request, Incident $incident, CurrentWorkspace $currentWorkspace, ChangeIncident $changes): RedirectResponse
    {
        $changes->update($incident, $currentWorkspace->get(), $request->user(), $request->validated());

        return to_route('monitor.incidents.show', $incident)->with('status', 'Incident updated.');
    }
}
