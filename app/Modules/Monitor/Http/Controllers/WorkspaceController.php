<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\CreateWorkspace;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\RecordAuditLog;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(CurrentWorkspace $currentWorkspace, WorkspacePlanLimits $limits): View
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('view', $workspace);
        $seatCapacity = $limits->seatCapacity($workspace);

        return view('monitor::settings.team', [
            'workspace' => $workspace,
            'members' => $workspace->members()->orderBy('name')->get(),
            'seatCapacity' => $seatCapacity,
            'invitations' => Gate::allows('update', $workspace)
                ? $workspace->invitations()->whereNull('accepted_at')->latest('id')->get()
                : collect(),
        ]);
    }

    public function create(): View
    {
        return view('monitor::workspaces.create');
    }

    public function store(Request $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $workspace = $createWorkspace->create($request->user(), $validated['name']);
        $request->session()->put('workspace_id', $workspace->id);

        return to_route('monitor.dashboard')->with('status', 'Workspace created.');
    }

    public function update(Request $request, Workspace $workspace, RecordAuditLog $audit): RedirectResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:120']])['name'];
        DB::connection('monitor')->transaction(function () use ($workspace, $request, $name, $audit): void {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::authorize('update', $workspace);
            $before = $workspace->name;
            $workspace->update(['name' => $name]);

            if ($before !== $workspace->name) {
                $audit->record($workspace, $request->user(), 'workspace.updated', $workspace, ['label' => $workspace->name, 'before' => $before, 'after' => $workspace->name]);
            }
        });

        return to_route('monitor.settings.team')->with('status', 'Workspace updated.');
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('view', $workspace);
        $request->session()->put('workspace_id', $workspace->id);

        return to_route('monitor.dashboard');
    }
}
