<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\SaveMaintenanceWindowRequest;
use App\Modules\Monitor\Models\MaintenanceWindow;
use App\Modules\Monitor\Services\ChangeMaintenanceWindow;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MaintenanceWindowController extends Controller
{
    public function index(CurrentWorkspace $currentWorkspace): View
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('view', $workspace);

        return view('monitor::maintenance-windows.index', [
            'workspace' => $workspace,
            'windows' => $workspace->maintenanceWindows()->latest('starts_at')->latest('id')->paginate(20),
            'canManage' => Gate::allows('update', $workspace),
        ]);
    }

    public function create(CurrentWorkspace $currentWorkspace): View
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('update', $workspace);

        return view('monitor::maintenance-windows.form', ['window' => new MaintenanceWindow([
            'starts_at' => now('UTC')->addHour()->startOfMinute(),
            'ends_at' => now('UTC')->addHours(2)->startOfMinute(),
        ])]);
    }

    public function store(SaveMaintenanceWindowRequest $request, CurrentWorkspace $currentWorkspace, ChangeMaintenanceWindow $changes): RedirectResponse
    {
        $changes->save($currentWorkspace->get(), $request->user(), $request->validated());

        return to_route('monitor.maintenance-windows.index')->with('status', 'Maintenance window scheduled. Alert notifications will be suppressed during it.');
    }

    public function edit(MaintenanceWindow $maintenanceWindow, CurrentWorkspace $currentWorkspace): View
    {
        $workspace = $currentWorkspace->get();
        $window = $workspace->maintenanceWindows()->findOrFail($maintenanceWindow->id);
        Gate::authorize('update', $workspace);

        return view('monitor::maintenance-windows.form', ['window' => $window]);
    }

    public function update(SaveMaintenanceWindowRequest $request, MaintenanceWindow $maintenanceWindow, CurrentWorkspace $currentWorkspace, ChangeMaintenanceWindow $changes): RedirectResponse
    {
        $changes->save($currentWorkspace->get(), $request->user(), $request->validated(), $maintenanceWindow);

        return to_route('monitor.maintenance-windows.index')->with('status', 'Maintenance window updated.');
    }

    public function destroy(Request $request, MaintenanceWindow $maintenanceWindow, CurrentWorkspace $currentWorkspace, ChangeMaintenanceWindow $changes): RedirectResponse
    {
        $changes->delete($currentWorkspace->get(), $request->user(), $maintenanceWindow);

        return to_route('monitor.maintenance-windows.index')->with('status', 'Maintenance window removed.');
    }
}
