<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Data\Telemetry\AlertMetric;
use App\Modules\Monitor\Http\Requests\ArchiveAlertRuleRequest;
use App\Modules\Monitor\Http\Requests\SaveAlertRuleRequest;
use App\Modules\Monitor\Http\Requests\SearchAlertsRequest;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Services\ChangeAlertRule;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AlertRuleController extends Controller
{
    public function index(SearchAlertsRequest $request, CurrentWorkspace $currentWorkspace, TelemetryRedactor $redactor): Response
    {
        $filters = $request->validated();
        $state = $filters['state'] ?? 'all';
        $query = AlertRule::forWorkspace($currentWorkspace->get())->visibleTo(request()->user(), $currentWorkspace->get())->with(['environment.application', 'metricSeries', 'serviceLevelObjective']);
        if ($state === 'archived') {
            $query->onlyTrashed();
        } elseif ($state !== 'all') {
            $query->where('enabled', $state === 'enabled');
        }
        $rules = $query->latest('created_at')->latest('id')->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($request->safe()->except('page'));
        $rules->each(fn (AlertRule $rule): AlertRule => $rule->forceFill($redactor->redact($rule->only(['name', 'service', 'match_text']))));

        return response()->view('monitor::alerts.index', [
            ...compact('rules', 'state'),
            'canCreate' => Gate::allows('create', [AlertRule::class, $currentWorkspace->get()]),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function create(SearchAlertsRequest $request, CurrentWorkspace $currentWorkspace): Response
    {
        $selectedSeries = $request->validated('series') !== null
            ? MetricSeries::forWorkspace($currentWorkspace->get())->visibleTo(request()->user(), $currentWorkspace->get())->findOrFail($request->validated('series')) : null;

        return $this->form($currentWorkspace, selectedSeries: $selectedSeries);
    }

    public function edit(SearchAlertsRequest $request, AlertRule $alertRule, CurrentWorkspace $currentWorkspace): Response
    {
        return $this->form($currentWorkspace, $alertRule);
    }

    private function form(CurrentWorkspace $currentWorkspace, ?AlertRule $alertRule = null, ?MetricSeries $selectedSeries = null): Response
    {
        $environments = Environment::forWorkspace($currentWorkspace->get())->visibleTo(request()->user(), $currentWorkspace->get())->with('application:id,name')
            ->orderBy('application_id')->orderBy('name')->orderBy('id')->get(['id', 'application_id', 'name', 'status']);
        $environmentOptions = $environments->mapWithKeys(fn (Environment $environment): array => [
            $environment->id => $environment->application->name.' / '.$environment->name.($environment->status === 'paused' ? ' (source paused)' : ''),
        ])->all();
        $metricSeriesOptions = MetricSeries::forWorkspace($currentWorkspace->get())->visibleTo(request()->user(), $currentWorkspace->get())->with('environment.application')
            ->orderBy('environment_id')->orderBy('name')->orderBy('resource_label')->orderBy('id')->limit(500)->get()
            ->mapWithKeys(fn (MetricSeries $series): array => [
                $series->id => $series->environment->application->name.' / '.$series->environment->name.' · '.$series->name.' · '.$series->resource_label,
            ])->all();
        $objectiveOptions = ServiceLevelObjective::forWorkspace($currentWorkspace->get())->visibleTo(request()->user(), $currentWorkspace->get())->where('enabled', true)->with('environment.application')
            ->orderBy('environment_id')->orderBy('name')->orderBy('id')->get()
            ->mapWithKeys(fn (ServiceLevelObjective $objective): array => [
                $objective->id => $objective->environment->application->name.' / '.$objective->environment->name.' · '.$objective->name.' · '.$objective->scopeLabel(),
            ])->all();

        return response()->view('monitor::alerts.form', [
            'alertRule' => $alertRule, 'environmentOptions' => $environmentOptions,
            'metricOptions' => collect(AlertMetric::cases())->mapWithKeys(fn (AlertMetric $metric): array => [$metric->value => $metric->label()])->all(),
            'windowOptions' => SaveAlertRuleRequest::WINDOWS,
            'metricSeriesOptions' => $metricSeriesOptions, 'objectiveOptions' => $objectiveOptions, 'selectedSeries' => $selectedSeries,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(SearchAlertsRequest $request, AlertRule $alertRule, TelemetryRedactor $redactor, CurrentWorkspace $workspace, WorkspacePlanLimits $limits): Response
    {
        $alertRule->load(['environment.application', 'metricSeries', 'serviceLevelObjective']);
        $alertRule->forceFill($redactor->redact($alertRule->only(['name', 'service', 'match_text'])));
        $incidents = $alertRule->incidents()->visibleTo($request->user(), $workspace->get())->latest('opened_at')->latest('id')
            ->paginate(20, ['*'], 'page', (int) ($request->validated('page') ?? 1));
        $incidents->each(fn (Incident $incident): Incident => $incident->forceFill($redactor->redact($incident->only('title'))));

        $destinations = collect();
        $routes = collect();
        $escalations = collect();
        $escalationDestinations = collect();
        $escalationLimit = 0;
        if (Gate::allows('update', $alertRule)) {
            $destinations = AlertDestination::forWorkspace($workspace->get())->orderBy('name')->orderBy('id')->get(['id', 'name', 'enabled']);
            $routes = $alertRule->destinations()->where('workspace_id', $workspace->get()->id)->get(['alert_destinations.id']);
            $escalations = $alertRule->escalations()->with('destination')->get();
            $escalationDestinations = $destinations->reject(fn (AlertDestination $destination): bool => $routes->contains('id', $destination->id))->values();
            $escalationLimit = $limits->escalationStepLimit($workspace->get());
        }

        return response()->view('monitor::alerts.show', compact('alertRule', 'incidents', 'destinations', 'routes', 'escalations', 'escalationDestinations', 'escalationLimit'))->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveAlertRuleRequest $request, CurrentWorkspace $currentWorkspace, ChangeAlertRule $changes): RedirectResponse
    {
        $rule = $changes->save($currentWorkspace->get(), $request->user(), $request->validated());

        return to_route('monitor.alerts.show', $rule)->with('status', 'Alert rule created. Evaluation begins after its first complete window.');
    }

    public function update(SaveAlertRuleRequest $request, AlertRule $alertRule, CurrentWorkspace $currentWorkspace, ChangeAlertRule $changes): RedirectResponse
    {
        $changes->save($currentWorkspace->get(), $request->user(), $request->validated(), $alertRule);

        return to_route('monitor.alerts.show', $alertRule)->with('status', 'Alert rule saved.');
    }

    public function destroy(ArchiveAlertRuleRequest $request, AlertRule $alertRule, CurrentWorkspace $currentWorkspace, ChangeAlertRule $changes): RedirectResponse
    {
        $changes->archive($alertRule, $currentWorkspace->get(), $request->user(), (int) $request->validated('version'));

        return to_route('monitor.alerts.index', ['state' => 'archived'])->with('status', 'Rule archived. Incident history is retained; this does not indicate recovery.');
    }
}
