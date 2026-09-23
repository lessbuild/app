<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\SaveStatusPageRequest;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Services\ChangeStatusPage;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class StatusPageController extends Controller
{
    public function index(CurrentWorkspace $currentWorkspace): View
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('view', $workspace);

        return view('monitor::status-pages.index', [
            'workspace' => $workspace,
            'statusPages' => $workspace->statusPages()->withCount('components')->latest('id')->paginate(12),
            'canManage' => Gate::allows('update', $workspace),
        ]);
    }

    public function create(CurrentWorkspace $currentWorkspace): View
    {
        return $this->form($currentWorkspace, new StatusPage(['published' => false]));
    }

    public function store(SaveStatusPageRequest $request, CurrentWorkspace $currentWorkspace, ChangeStatusPage $changes): RedirectResponse
    {
        $page = $changes->save($currentWorkspace->get(), $request->user(), $request->validated());

        return to_route('monitor.status-pages.edit', $page)->with('status', 'Status page saved. Share its public URL when you are ready.');
    }

    public function edit(StatusPage $statusPage, CurrentWorkspace $currentWorkspace): View
    {
        $workspace = $currentWorkspace->get();
        $statusPage = $workspace->statusPages()->with('components')->findOrFail($statusPage->id);

        return $this->form($currentWorkspace, $statusPage);
    }

    public function update(SaveStatusPageRequest $request, StatusPage $statusPage, CurrentWorkspace $currentWorkspace, ChangeStatusPage $changes): RedirectResponse
    {
        $page = $changes->save($currentWorkspace->get(), $request->user(), $request->validated(), $statusPage);

        return to_route('monitor.status-pages.edit', $page)->with('status', 'Status page updated.');
    }

    public function destroy(Request $request, StatusPage $statusPage, CurrentWorkspace $currentWorkspace, ChangeStatusPage $changes): RedirectResponse
    {
        $changes->delete($currentWorkspace->get(), $request->user(), $statusPage);

        return to_route('monitor.status-pages.index')->with('status', 'Status page deleted.');
    }

    private function form(CurrentWorkspace $currentWorkspace, StatusPage $statusPage): View
    {
        $workspace = $currentWorkspace->get();
        Gate::authorize('update', $workspace);
        $monitors = Monitor::query()->forWorkspace($workspace)->with('environment.application')->orderBy('name')->orderBy('id')->get();
        $selectedMonitorIds = $statusPage->components->pluck('monitor_id')->map(fn (int $id): int => $id)->all();

        return view('monitor::status-pages.form', compact('statusPage', 'monitors', 'selectedMonitorIds'));
    }
}
