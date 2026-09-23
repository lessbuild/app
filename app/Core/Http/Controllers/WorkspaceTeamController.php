<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceInvitation;
use App\Core\Models\WorkspaceMembership;
use App\Core\Notifications\WorkspaceInvitationNotification;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\WorkspaceProjectAccess;
use App\Core\Services\Workspaces\CreateWorkspaceInvitation;
use App\Core\Services\Workspaces\RevokeWorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class WorkspaceTeamController
{
    public function index(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        abort_if($access->activeMembership($user, $workspace) === null, 404);

        $members = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->currentlyActive()
            ->with('user')
            ->orderBy('created_at')
            ->paginate(25);

        $invitations = WorkspaceInvitation::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('status', ['pending', 'accepted', 'revoked'])
            ->with('invitedBy')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $workspaces = $user->workspaceMemberships()
            ->currentlyActive()
            ->whereHas('workspace', fn ($query) => $query
                ->where('status', 'active')
                ->whereNull('archived_at'))
            ->with('workspace')
            ->get()
            ->pluck('workspace')
            ->filter();

        return view('core::workspaces.team', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'members' => $members,
            'invitations' => $invitations,
            'canManageMembers' => $access->canManageWorkspace($user, $workspace),
        ]);
    }

    public function storeInvitation(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        CreateWorkspaceInvitation $createInvitation,
    ): RedirectResponse {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'role' => ['required', 'string', Rule::in(['admin', 'billing', 'member', 'viewer'])],
        ]);

        $user = $this->platformUser($request, $platformUsers);
        $created = $createInvitation->handle($user, $workspace, $data['email'], $data['role']);

        Notification::route('mail', $created->invitation->email)->notify(new WorkspaceInvitationNotification(
            workspaceName: $workspace->name,
            inviterName: $user->name ?: $user->email,
            role: $created->invitation->role,
            token: $created->token,
        ));

        return redirect()->route('core.workspace.team.index', $workspace)
            ->with('success', __('Invitation sent to :email.', ['email' => $created->invitation->email]));
    }

    public function revokeInvitation(
        Request $request,
        Workspace $workspace,
        WorkspaceInvitation $invitation,
        ResolvePlatformUser $platformUsers,
        RevokeWorkspaceInvitation $revokeInvitation,
    ): RedirectResponse {
        $revokeInvitation->handle($this->platformUser($request, $platformUsers), $workspace, $invitation);

        return redirect()->route('core.workspace.team.index', $workspace)->with('success', __('Invitation revoked.'));
    }

    private function platformUser(Request $request, ResolvePlatformUser $platformUsers): PlatformUser
    {
        $principal = $request->user('platform');
        abort_unless($principal !== null, 401);

        $user = $platformUsers->resolve($principal, 'deployer');
        abort_if($user === null, 403);

        return $user;
    }
}
