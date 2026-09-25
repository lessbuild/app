<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\DeleteWorkspaceMonitorMaintenanceWindowRequest;
use App\Core\Http\Requests\SaveWorkspaceMonitorMaintenanceWindowRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceMonitorAdministrationRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class WorkspaceMonitorMaintenanceWindowController
{
    public function index(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): Response
    {
        [$user, $workspaces] = $this->coreContext($request, $workspace, $access);
        $page = $request->validate(['maintenance_page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $provider = $providers->maintenanceWindows();
        abort_if($provider === null, 404);
        $snapshot = $provider->snapshot($user, $workspace, array_filter($page, fn ($value) => $value !== null && $value !== ''));
        abort_if($snapshot === null, 404);

        return response()->view('core::workspaces.monitor.maintenance-windows', $this->viewContext($user, $workspace, $workspaces, $access) + [
            'items' => $snapshot->items,
            'canManage' => $snapshot->canManage,
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function store(SaveWorkspaceMonitorMaintenanceWindowRequest $request, Workspace $workspace, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        $user = $this->requestUser($request);
        $provider = $providers->maintenanceWindows();
        abort_if($provider === null, 404);

        try {
            $provider->saveWindow($user, $workspace, null, $request->validated());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return to_route('core.workspace.monitor.maintenance-windows', $workspace)->with('status', 'Maintenance window scheduled.');
    }

    public function update(SaveWorkspaceMonitorMaintenanceWindowRequest $request, Workspace $workspace, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        $user = $this->requestUser($request);
        $provider = $providers->maintenanceWindows();
        abort_if($provider === null, 404);
        $data = $request->validated();
        $reference = $data['window_reference'] ?? null;
        abort_unless(is_string($reference) && filled($data['version'] ?? null), 422);

        try {
            $provider->saveWindow($user, $workspace, $reference, $data);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return to_route('core.workspace.monitor.maintenance-windows', $workspace)->with('status', 'Maintenance window updated.');
    }

    public function destroy(DeleteWorkspaceMonitorMaintenanceWindowRequest $request, Workspace $workspace, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        $user = $this->requestUser($request);
        $provider = $providers->maintenanceWindows();
        abort_if($provider === null, 404);
        $data = $request->validated();
        $provider->deleteWindow($user, $workspace, $data['window_reference'], $data['version'], in_array($data['confirm_remove'], [true, 1, '1', 'yes', 'on'], true));

        return to_route('core.workspace.monitor.maintenance-windows', $workspace)->with('status', 'Maintenance window removed.');
    }

    private function requestUser(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }

    /** @return array{PlatformUser, Collection<int, Workspace>} */
    private function coreContext(Request $request, Workspace $workspace, WorkspaceProjectAccess $access): array
    {
        $user = $this->requestUser($request);
        abort_if($access->activeMembership($user, $workspace) === null, 404);
        $workspaces = Workspace::query()->where('status', 'active')->whereNull('archived_at')
            ->whereHas('memberships', fn ($query) => $query->currentlyActive()->where('user_id', $user->getKey()))
            ->orderBy('name')->get();

        return [$user, $workspaces];
    }

    /** @param Collection<int, Workspace> $workspaces
     * @return array<string, mixed>
     */
    private function viewContext(PlatformUser $user, Workspace $workspace, Collection $workspaces, WorkspaceProjectAccess $access): array
    {
        $contextProjects = $access->accessibleProductProjects($user, $workspace, 'monitor')
            ->orderBy('name')->limit(30)->get(['id', 'workspace_id', 'name'])
            ->map(fn (Project $project): array => [
                'id' => (string) $project->getKey(),
                'name' => $project->name,
                'href' => route('core.projects.show', [$workspace, $project]),
            ]);

        return [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'contextProjects' => $contextProjects,
        ];
    }
}
