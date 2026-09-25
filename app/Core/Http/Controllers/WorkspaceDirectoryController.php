<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\StoreWorkspaceRequest;
use App\Core\Models\PlatformUser;
use App\Core\Services\Workspaces\CreatePlatformWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkspaceDirectoryController
{
    public function index(Request $request): View
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        $workspaces = $user->workspaceMemberships()
            ->currentlyActive()
            ->whereHas('workspace', fn (Builder $query) => $query
                ->where('status', 'active')
                ->whereNull('archived_at'))
            ->with('workspace')
            ->get()
            ->pluck('workspace')
            ->filter()
            ->values();

        return view('core::workspaces.index', [
            'user' => $user,
            'workspaces' => $workspaces,
            'canCreateWorkspace' => $user->status === 'active' && $user->hasVerifiedEmail(),
        ]);
    }

    public function store(StoreWorkspaceRequest $request, CreatePlatformWorkspace $createWorkspace): RedirectResponse
    {
        $owner = $request->user('platform');
        abort_unless($owner instanceof PlatformUser, 401);

        $workspace = $createWorkspace->handle($owner, $request->validated('name'));

        return redirect()
            ->route('core.workspace.dashboard', $workspace)
            ->with('success', __('Workspace created. Connect Deployer, Monitor, or Analytics when you are ready.'));
    }
}
