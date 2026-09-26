<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceAdministrationCatalog;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkspaceAdministrationController
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceAdministrationCatalog $catalog,
    ): View {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);

        return view('core::workspaces.admin', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => Workspace::query()
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->whereHas('memberships', fn (Builder $query) => $query->currentlyActive()->where('user_id', $user->getKey()))
                ->orderBy('name')
                ->get(),
            'moduleTools' => $catalog->forMembership($membership),
            'canManageWorkspace' => $access->canManageWorkspace($user, $workspace),
        ]);
    }
}
