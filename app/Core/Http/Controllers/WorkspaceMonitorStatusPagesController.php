<?php

namespace App\Core\Http\Controllers;

use App\Core\Contracts\WorkspaceMonitorStatusManagementProvider;
use App\Core\Data\Status\WorkspaceMonitorStatusManagement;
use App\Core\Http\Requests\SaveWorkspaceMonitorStatusPageRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceMonitorStatusManagementProviderRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkspaceMonitorStatusPagesController
{
    public function index(
        Request $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceMonitorStatusManagementProviderRegistry $providers,
    ): View {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);
        $this->authorizeWorkspace($user, $workspace, $access);

        return $this->view($user, $workspace, $providers->get('monitor')?->forWorkspace($user, $workspace));
    }

    public function store(
        SaveWorkspaceMonitorStatusPageRequest $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceMonitorStatusManagementProviderRegistry $providers,
    ): RedirectResponse {
        [$user, $provider] = $this->managerContext($request, $workspace, $access, $providers);
        abort_unless($provider->create($user, $workspace, $request->validated()), 404);

        return to_route('core.workspace.monitor-status-pages.index', $workspace)->with('success', __('Monitor status page created.'));
    }

    public function update(
        SaveWorkspaceMonitorStatusPageRequest $request,
        Workspace $workspace,
        string $page,
        WorkspaceProjectAccess $access,
        WorkspaceMonitorStatusManagementProviderRegistry $providers,
    ): RedirectResponse {
        [$user, $provider] = $this->managerContext($request, $workspace, $access, $providers);
        abort_unless($provider->update($user, $workspace, $page, $request->validated()), 404);

        return to_route('core.workspace.monitor-status-pages.index', $workspace)->with('success', __('Monitor status page updated.'));
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        string $page,
        WorkspaceProjectAccess $access,
        WorkspaceMonitorStatusManagementProviderRegistry $providers,
    ): RedirectResponse {
        [$user, $provider] = $this->managerContext($request, $workspace, $access, $providers);
        abort_unless($provider->delete($user, $workspace, $page), 404);

        return to_route('core.workspace.monitor-status-pages.index', $workspace)->with('success', __('Monitor status page deleted.'));
    }

    /** @return array{PlatformUser, WorkspaceMonitorStatusManagementProvider} */
    private function managerContext(
        Request $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceMonitorStatusManagementProviderRegistry $providers,
    ): array {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);
        $this->authorizeWorkspace($user, $workspace, $access);

        $provider = $providers->get('monitor');
        abort_if($provider === null, 404);
        $management = $provider->forWorkspace($user, $workspace);
        abort_if($management === null, 404);
        abort_unless($management->canManage, 403);

        return [$user, $provider];
    }

    private function authorizeWorkspace(PlatformUser $user, Workspace $workspace, WorkspaceProjectAccess $access): void
    {
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null || ! $access->hasProductAccess($membership, 'monitor'), 404);
    }

    private function view(
        PlatformUser $user,
        Workspace $workspace,
        ?WorkspaceMonitorStatusManagement $management,
    ): View {
        return view('core::workspaces.monitor-status-pages', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => Workspace::query()
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->whereHas('memberships', fn (Builder $query) => $query->currentlyActive()->where('user_id', $user->getKey()))
                ->orderBy('name')
                ->get(),
            'management' => $management,
        ]);
    }
}
