<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\SaveWorkspaceMonitorConfigurationRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceMonitorAdministrationRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WorkspaceMonitorConfigurationController
{
    public function index(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): Response
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->configuration();
        abort_if($provider === null, 503);
        $filters = $request->validate([
            'application_search' => ['nullable', 'string', 'max:100'], 'applications_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'environment_search' => ['nullable', 'string', 'max:100'], 'environments_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'check_search' => ['nullable', 'string', 'max:100'], 'checks_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $snapshot = $provider->snapshot($user, $workspace, array_filter($filters, fn ($value) => $value !== null && $value !== ''));
        abort_if($snapshot === null, 404);

        return response()->view('core::workspaces.monitor.configuration', $this->pageData($user, $workspace, $access) + compact('snapshot'))
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function updateApplication(SaveWorkspaceMonitorConfigurationRequest $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->configuration();
        abort_if($provider === null, 503);
        $data = $request->validated();
        $reference = $data['application_reference'];
        unset($data['application_reference']);
        $provider->updateApplication($user, $workspace, $reference, $data);

        return to_route('core.workspace.monitor.configuration.index', $workspace)->with('status', __('Monitor application settings saved.'));
    }

    public function updateEnvironment(SaveWorkspaceMonitorConfigurationRequest $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->configuration();
        abort_if($provider === null, 503);
        $data = $request->validated();
        $reference = $data['environment_reference'];
        unset($data['environment_reference']);
        $provider->updateEnvironment($user, $workspace, $reference, $data);

        return to_route('core.workspace.monitor.configuration.index', $workspace)->with('status', __('Monitor environment settings saved.'));
    }

    public function updateMonitor(SaveWorkspaceMonitorConfigurationRequest $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        $user = $this->authorizeWorkspace($request, $workspace, $access);
        $provider = $providers->configuration();
        abort_if($provider === null, 503);
        $data = $request->validated();
        $reference = $data['monitor_reference'];
        unset($data['monitor_reference']);
        $provider->updateMonitor($user, $workspace, $reference, $data);

        return to_route('core.workspace.monitor.configuration.index', $workspace)->with('status', __('Monitor check settings saved.'));
    }

    private function authorizeWorkspace(Request $request, Workspace $workspace, WorkspaceProjectAccess $access): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);
        abort_unless($access->hasProductAccess($membership, 'monitor'), 404);

        return $user;
    }

    /** @return array<string, mixed> */
    private function pageData(PlatformUser $user, Workspace $workspace, WorkspaceProjectAccess $access): array
    {
        $workspaces = Workspace::query()->where('status', 'active')->whereNull('archived_at')
            ->whereHas('memberships', fn (Builder $query) => $query->currentlyActive()->where('user_id', $user->getKey()))
            ->orderBy('name')->get();
        $projects = $access->accessibleProductProjects($user, $workspace, 'monitor')
            ->orderBy('name')->limit(30)->get(['id', 'workspace_id', 'name']);

        return [
            'user' => $user, 'accountUser' => $user, 'workspace' => $workspace, 'currentWorkspace' => $workspace,
            'workspaces' => $workspaces, 'navigation' => [],
            'contextProjects' => $projects->map(fn (Project $project): array => [
                'id' => (string) $project->getKey(), 'name' => $project->name,
                'href' => route('core.projects.show', [$workspace, $project]),
            ]),
        ];
    }
}
