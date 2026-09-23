<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\StoreWorkspaceDashboardViewRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceDashboardView;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProjectPin;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WorkspaceDashboardPreferencesController
{
    public function storeView(
        StoreWorkspaceDashboardViewRequest $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): RedirectResponse {
        $user = $this->platformUser($request, $platformUsers);
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);

        $data = $request->validated();
        $this->authorizeVisibility($data['visibility'], $user, $workspace, $membership, $access);

        $scopeKey = $this->scopeKey($data['visibility'], $user);
        $this->assertUniqueName($workspace, $scopeKey, $data['name']);

        $view = WorkspaceDashboardView::query()->create([
            'workspace_id' => $workspace->getKey(),
            'visibility' => $data['visibility'],
            'scope_key' => $scopeKey,
            'owner_user_id' => $data['visibility'] === 'personal' ? $user->getKey() : null,
            'created_by_user_id' => $user->getKey(),
            'name' => $data['name'],
            'filters' => $this->filters($data),
        ]);

        return $this->dashboardRedirect($request, $workspace, $user, $access, $view->getKey())
            ->with('success', __('Saved view created.'));
    }

    public function updateView(
        StoreWorkspaceDashboardViewRequest $request,
        Workspace $workspace,
        WorkspaceDashboardView $view,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): RedirectResponse {
        abort_unless($view->workspace_id === $workspace->getKey(), 404);

        $user = $this->platformUser($request, $platformUsers);
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);
        $this->authorizeManageView($view, $user, $workspace, $access);

        $data = $request->validated();
        $this->authorizeVisibility($data['visibility'], $user, $workspace, $membership, $access);

        $scopeKey = $this->scopeKey($data['visibility'], $user);
        $this->assertUniqueName($workspace, $scopeKey, $data['name'], $view);

        $view->forceFill([
            'visibility' => $data['visibility'],
            'scope_key' => $scopeKey,
            'owner_user_id' => $data['visibility'] === 'personal' ? $user->getKey() : null,
            'name' => $data['name'],
            'filters' => $this->filters($data),
        ])->save();

        return $this->dashboardRedirect($request, $workspace, $user, $access, $view->getKey())
            ->with('success', __('Saved view updated.'));
    }

    public function destroyView(
        Request $request,
        Workspace $workspace,
        WorkspaceDashboardView $view,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): RedirectResponse {
        abort_unless($view->workspace_id === $workspace->getKey(), 404);

        $user = $this->platformUser($request, $platformUsers);
        abort_if($access->activeMembership($user, $workspace) === null, 404);
        $this->authorizeManageView($view, $user, $workspace, $access);

        $view->delete();

        return $this->dashboardRedirect($request, $workspace, $user, $access)
            ->with('success', __('Saved view deleted.'));
    }

    public function pin(
        Request $request,
        Workspace $workspace,
        Project $project,
        string $visibility,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): RedirectResponse {
        $user = $this->platformUser($request, $platformUsers);
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);
        abort_unless($project->workspace_id === $workspace->getKey() && $access->canViewProject($user, $project), 404);
        $this->authorizeVisibility($visibility, $user, $workspace, $membership, $access);

        $scopeKey = $this->scopeKey($visibility, $user);
        WorkspaceProjectPin::query()->firstOrCreate([
            'workspace_id' => $workspace->getKey(),
            'project_id' => $project->getKey(),
            'scope_key' => $scopeKey,
        ], [
            'visibility' => $visibility,
            'owner_user_id' => $visibility === 'personal' ? $user->getKey() : null,
            'created_by_user_id' => $user->getKey(),
        ]);

        return $this->dashboardRedirect($request, $workspace, $user, $access)
            ->with('success', $visibility === 'workspace' ? __('Project pinned for the workspace.') : __('Project pinned for you.'));
    }

    public function unpin(
        Request $request,
        Workspace $workspace,
        Project $project,
        string $visibility,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): RedirectResponse {
        $user = $this->platformUser($request, $platformUsers);
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);
        abort_unless($project->workspace_id === $workspace->getKey() && $access->canViewProject($user, $project), 404);
        $this->authorizeVisibility($visibility, $user, $workspace, $membership, $access);

        WorkspaceProjectPin::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('project_id', $project->getKey())
            ->where('scope_key', $this->scopeKey($visibility, $user))
            ->delete();

        return $this->dashboardRedirect($request, $workspace, $user, $access)
            ->with('success', $visibility === 'workspace' ? __('Workspace pin removed.') : __('Personal pin removed.'));
    }

    private function platformUser(Request $request, ResolvePlatformUser $platformUsers): PlatformUser
    {
        $principal = $request->user('platform') ?? $request->user();
        abort_unless($principal !== null, 401);

        $user = $platformUsers->resolve($principal, 'deployer');
        abort_if($user === null, 403);

        return $user;
    }

    private function authorizeVisibility(
        string $visibility,
        PlatformUser $user,
        Workspace $workspace,
        WorkspaceMembership $membership,
        WorkspaceProjectAccess $access,
    ): void {
        abort_unless(in_array($visibility, ['personal', 'workspace'], true), 404);

        if ($visibility === 'workspace') {
            abort_unless($access->canManageWorkspace($user, $workspace), 403);
        }
    }

    private function authorizeManageView(
        WorkspaceDashboardView $view,
        PlatformUser $user,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
    ): void {
        if ($view->visibility === 'personal') {
            abort_unless($view->owner_user_id === $user->getKey(), 404);

            return;
        }

        abort_unless($access->canManageWorkspace($user, $workspace), 403);
    }

    /** @return array{product:string,pinned_only:bool,project_name:string} */
    private function filters(array $data): array
    {
        return [
            'product' => $data['product'],
            'pinned_only' => filter_var($data['pinned_only'], FILTER_VALIDATE_BOOLEAN),
            'project_name' => trim((string) ($data['project_name'] ?? '')),
        ];
    }

    private function scopeKey(string $visibility, PlatformUser $user): string
    {
        return $visibility === 'workspace' ? 'workspace' : 'user:'.$user->getKey();
    }

    private function assertUniqueName(Workspace $workspace, string $scopeKey, string $name, ?WorkspaceDashboardView $ignore = null): void
    {
        $query = WorkspaceDashboardView::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('scope_key', $scopeKey)
            ->where('name', $name);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore->getKey());
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => __('You already have a saved view with this name in that scope.')]);
        }
    }

    private function dashboardRedirect(
        Request $request,
        Workspace $workspace,
        PlatformUser $user,
        WorkspaceProjectAccess $access,
        ?string $fallbackViewId = null,
    ): RedirectResponse {
        $viewId = $fallbackViewId ?? $request->input('return_view');
        $urlParameters = ['workspace' => $workspace];

        if ($viewId === 'all') {
            $urlParameters['view'] = 'all';
        } elseif (filled($viewId)) {
            $membership = $access->activeMembership($user, $workspace);
            $view = WorkspaceDashboardView::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($viewId)
                ->where(fn ($query) => $query
                    ->where('visibility', 'workspace')
                    ->orWhere('owner_user_id', $user->getKey()))
                ->first();

            if ($membership !== null && $view !== null) {
                $urlParameters['view'] = $view->getKey();
            }
        }

        return redirect()->route('core.workspace.dashboard', $urlParameters);
    }
}
