<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\ArchiveServiceLevelObjectiveRequest;
use App\Modules\Monitor\Http\Requests\SaveServiceLevelObjectiveRequest;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ChangeServiceLevelObjective;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\ServiceObjectiveBurnRate;
use App\Modules\Monitor\Services\ServiceObjectiveReport;
use App\Modules\Monitor\Services\ServiceObjectiveReportExporter;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ServiceLevelObjectiveController extends Controller
{
    public function index(CurrentWorkspace $currentWorkspace): Response
    {
        $workspace = $currentWorkspace->get();
        $objectives = ServiceLevelObjective::forWorkspace($workspace)->with('environment.application')
            ->orderByDesc('enabled')->orderBy('name')->orderBy('id')->paginate(25);

        return response()->view('monitor::objectives.index', [
            'objectives' => $objectives,
            'canManage' => Gate::allows('update', $workspace),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function create(CurrentWorkspace $currentWorkspace): Response
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('update', $workspace);

        return $this->form($workspace);
    }

    public function store(SaveServiceLevelObjectiveRequest $request, CurrentWorkspace $currentWorkspace, ChangeServiceLevelObjective $changes): RedirectResponse
    {
        $objective = $changes->save($currentWorkspace->get(), $request->user(), $request->validated());

        return to_route('monitor.objectives.show', $objective)->with('status', 'Service objective created.');
    }

    public function show(ServiceLevelObjective $serviceLevelObjective, CurrentWorkspace $currentWorkspace, ServiceObjectiveReport $reports, ServiceObjectiveBurnRate $burnRates, WorkspacePlanLimits $limits): Response
    {
        $workspace = $currentWorkspace->get();
        $objective = ServiceLevelObjective::forWorkspace($workspace)->with('environment.application')->findOrFail($serviceLevelObjective->id);

        return response()->view('monitor::objectives.show', [
            'objective' => $objective,
            'report' => $reports->forObjective($objective),
            'burnRate' => $limits->sloBurnRateEnabled($workspace) ? $burnRates->forObjective($objective) : null,
            'sloReportsEnabled' => $limits->sloReportsEnabled($workspace),
            'canManage' => Gate::allows('update', $workspace),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function export(ServiceLevelObjective $serviceLevelObjective, CurrentWorkspace $currentWorkspace, ServiceObjectiveReport $reports, ServiceObjectiveReportExporter $exporter, WorkspacePlanLimits $limits): Response
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('view', $workspace);
        abort_unless($limits->sloReportsEnabled($workspace), 403, 'SLO reports and exports are available on Team and Scale plans.');
        $objective = ServiceLevelObjective::forWorkspace($workspace)->with('environment.application')->findOrFail($serviceLevelObjective->id);
        $report = $reports->forObjective($objective);
        $filename = $exporter->filename($objective, $report['until']);

        return response($exporter->csv($objective, $report), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function edit(ServiceLevelObjective $serviceLevelObjective, CurrentWorkspace $currentWorkspace): Response
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('update', $workspace);
        $objective = ServiceLevelObjective::forWorkspace($workspace)->findOrFail($serviceLevelObjective->id);

        return $this->form($workspace, $objective);
    }

    public function update(SaveServiceLevelObjectiveRequest $request, ServiceLevelObjective $serviceLevelObjective, CurrentWorkspace $currentWorkspace, ChangeServiceLevelObjective $changes): RedirectResponse
    {
        $objective = $changes->save($currentWorkspace->get(), $request->user(), $request->validated(), $serviceLevelObjective);

        return to_route('monitor.objectives.show', $objective)->with('status', 'Service objective saved.');
    }

    public function destroy(ArchiveServiceLevelObjectiveRequest $request, ServiceLevelObjective $serviceLevelObjective, CurrentWorkspace $currentWorkspace, ChangeServiceLevelObjective $changes): RedirectResponse
    {
        $changes->archive($serviceLevelObjective, $currentWorkspace->get(), $request->user());

        return to_route('monitor.objectives.index')->with('status', 'Service objective archived.');
    }

    private function form(Workspace $workspace, ?ServiceLevelObjective $objective = null): Response
    {
        $environments = Environment::forWorkspace($workspace)->with('application:id,name')
            ->orderBy('application_id')->orderBy('name')->orderBy('id')->get(['id', 'application_id', 'name', 'status']);
        $environmentOptions = $environments->mapWithKeys(fn (Environment $environment): array => [
            $environment->id => $environment->application->name.' / '.$environment->name.($environment->status !== 'active' ? ' (paused)' : ''),
        ])->all();

        return response()->view('monitor::objectives.form', compact('objective', 'environmentOptions'))
            ->header('Cache-Control', 'private, no-store');
    }
}
