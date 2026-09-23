<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\PlatformUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class CoreHomeController
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        /** @var PlatformUser $user */
        $user = $request->user('platform');

        $workspaces = $user->workspaceMemberships()
            ->where('status', 'active')
            ->whereHas('workspace', fn ($query) => $query
                ->where('status', 'active')
                ->whereNull('archived_at'))
            ->with('workspace')
            ->get()
            ->pluck('workspace')
            ->filter();

        if ($workspaces->count() === 1) {
            return redirect()->route('core.workspace.dashboard', $workspaces->first());
        }

        return view('core::workspaces.index', [
            'user' => $user,
            'workspaces' => $workspaces,
        ]);
    }
}
