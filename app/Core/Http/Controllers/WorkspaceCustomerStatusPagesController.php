<?php

namespace App\Core\Http\Controllers;

use App\Core\Contracts\WorkspaceCustomerStatusManagementProvider;
use App\Core\Data\Status\WorkspaceCustomerStatusManagement;
use App\Core\Http\Requests\StoreWorkspaceCustomerStatusIncidentRequest;
use App\Core\Http\Requests\StoreWorkspaceCustomerStatusPageRequest;
use App\Core\Http\Requests\UpdateWorkspaceCustomerStatusIncidentRequest;
use App\Core\Http\Requests\UpdateWorkspaceCustomerStatusPageRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceCustomerStatusManagementProviderRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkspaceCustomerStatusPagesController
{
    public function index(
        Request $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceCustomerStatusManagementProviderRegistry $providers,
    ): View {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null || ! $access->hasProductAccess($membership, 'deployer'), 404);

        return $this->view($user, $workspace, $providers->get('deployer')?->forWorkspace($user, $workspace));
    }

    public function storePage(
        StoreWorkspaceCustomerStatusPageRequest $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceCustomerStatusManagementProviderRegistry $providers,
    ): RedirectResponse {
        [$user, $provider] = $this->managerContext($request, $workspace, $access, $providers);
        if (! $provider->createPage($user, $workspace, $request->validated())) {
            return back()->withErrors(['website_ids' => __('Choose one or more websites that belong to this workspace.')]);
        }

        return to_route('core.workspace.status-pages.index', $workspace)->with('success', __('Status page created.'));
    }

    public function updatePage(
        UpdateWorkspaceCustomerStatusPageRequest $request,
        Workspace $workspace,
        string $page,
        WorkspaceProjectAccess $access,
        WorkspaceCustomerStatusManagementProviderRegistry $providers,
    ): RedirectResponse {
        [$user, $provider] = $this->managerContext($request, $workspace, $access, $providers);
        if (! $provider->updatePage($user, $workspace, $page, $request->validated())) {
            return back()->withErrors(['website_ids' => __('The status page or selected websites are not available in this workspace.')]);
        }

        return to_route('core.workspace.status-pages.index', $workspace)->with('success', __('Status page updated.'));
    }

    public function destroyPage(
        Request $request,
        Workspace $workspace,
        string $page,
        WorkspaceProjectAccess $access,
        WorkspaceCustomerStatusManagementProviderRegistry $providers,
    ): RedirectResponse {
        [$user, $provider] = $this->managerContext($request, $workspace, $access, $providers);
        abort_unless($provider->deletePage($user, $workspace, $page), 404);

        return to_route('core.workspace.status-pages.index', $workspace)->with('success', __('Status page deleted.'));
    }

    public function storeIncident(
        StoreWorkspaceCustomerStatusIncidentRequest $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceCustomerStatusManagementProviderRegistry $providers,
    ): RedirectResponse {
        [$user, $provider] = $this->managerContext($request, $workspace, $access, $providers);
        abort_unless($provider->createIncident($user, $workspace, $request->validated()), 404);

        return to_route('core.workspace.status-pages.index', $workspace)->with('success', __('Status update published and eligible subscribers notified.'));
    }

    public function updateIncident(
        UpdateWorkspaceCustomerStatusIncidentRequest $request,
        Workspace $workspace,
        string $incident,
        WorkspaceProjectAccess $access,
        WorkspaceCustomerStatusManagementProviderRegistry $providers,
    ): RedirectResponse {
        [$user, $provider] = $this->managerContext($request, $workspace, $access, $providers);
        abort_unless($provider->updateIncident($user, $workspace, $incident, $request->validated()), 404);

        return to_route('core.workspace.status-pages.index', $workspace)->with('success', __('Status update saved and eligible subscribers notified.'));
    }

    /** @return array{PlatformUser, WorkspaceCustomerStatusManagementProvider} */
    private function managerContext(
        Request $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceCustomerStatusManagementProviderRegistry $providers,
    ): array {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null || ! $access->hasProductAccess($membership, 'deployer'), 404);

        $provider = $providers->get('deployer');
        abort_if($provider === null, 404);
        $management = $provider->forWorkspace($user, $workspace);
        abort_if($management === null, 404);
        abort_unless($management->canManage, 403);

        return [$user, $provider];
    }

    private function view(
        PlatformUser $user,
        Workspace $workspace,
        ?WorkspaceCustomerStatusManagement $management,
    ): View {
        return view('core::workspaces.status-pages', [
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
