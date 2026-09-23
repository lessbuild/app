<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\SaveDashboardRequest;
use App\Modules\Monitor\Models\Dashboard;
use App\Modules\Monitor\Services\ChangeDashboard;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\DashboardReport;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SavedDashboardController extends Controller
{
    public function index(CurrentWorkspace $currentWorkspace, WorkspacePlanLimits $limits): View
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('view', $workspace);

        return view('monitor::dashboards.index', [
            'dashboards' => $workspace->dashboards()->withCount('widgets')->with('creator:id,name')
                ->latest('id')->paginate(12),
            'capacity' => $limits->dashboardCapacity($workspace),
            'canManage' => Gate::allows('update', $workspace),
        ]);
    }

    public function create(CurrentWorkspace $currentWorkspace): View
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('update', $workspace);

        return $this->form(new Dashboard(['range' => '24h']));
    }

    public function store(SaveDashboardRequest $request, CurrentWorkspace $currentWorkspace, ChangeDashboard $changes): RedirectResponse
    {
        $dashboard = $changes->save($currentWorkspace->get(), $request->user(), $request->validated());

        return to_route('monitor.dashboards.show', $dashboard)->with('status', 'Dashboard saved.');
    }

    public function show(Dashboard $dashboard, CurrentWorkspace $currentWorkspace, DashboardReport $reports): View
    {
        $workspace = $currentWorkspace->get();
        $dashboard = $workspace->dashboards()->with(['widgets', 'creator:id,name'])->findOrFail($dashboard->id);

        return view('monitor::dashboards.show', [
            'dashboard' => $dashboard,
            'report' => $reports->forDashboard($dashboard, $workspace),
            'canManage' => Gate::allows('update', $workspace),
        ]);
    }

    public function edit(Dashboard $dashboard, CurrentWorkspace $currentWorkspace): View
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('update', $workspace);
        $dashboard = $workspace->dashboards()->with('widgets')->findOrFail($dashboard->id);

        return $this->form($dashboard);
    }

    public function update(SaveDashboardRequest $request, Dashboard $dashboard, CurrentWorkspace $currentWorkspace, ChangeDashboard $changes): RedirectResponse
    {
        $dashboard = $changes->save($currentWorkspace->get(), $request->user(), $request->validated(), $dashboard);

        return to_route('monitor.dashboards.show', $dashboard)->with('status', 'Dashboard updated.');
    }

    public function destroy(Request $request, Dashboard $dashboard, CurrentWorkspace $currentWorkspace, ChangeDashboard $changes): RedirectResponse
    {
        $changes->delete($currentWorkspace->get(), $request->user(), $dashboard);

        return to_route('monitor.dashboards.index')->with('status', 'Dashboard deleted.');
    }

    private function form(Dashboard $dashboard): View
    {
        return view('monitor::dashboards.form', ['dashboard' => $dashboard, 'widgetTypes' => Dashboard::WIDGET_TYPES]);
    }
}
