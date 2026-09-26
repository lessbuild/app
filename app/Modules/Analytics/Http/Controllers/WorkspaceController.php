<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\PlatformCoreRouteLinks;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(
        Request $request,
        AnalyticsWorkspaceAccess $access,
        ProductAuthentication $authentication,
        PlatformCoreRouteLinks $coreLinks,
    ): View {
        $workspaces = $access->workspacesFor($request->user())
            ->each(fn (Workspace $workspace) => $workspace->setAttribute('sites_count', $workspace->sites->count()));

        $usesCoreAuthority = $authentication->usesCoreAuthority('analytics');

        return view('analytics::workspaces.index', [
            'workspaces' => $workspaces,
            'usesCoreAuthority' => $usesCoreAuthority,
            'coreWorkspaceManagementUrl' => $usesCoreAuthority ? $coreLinks->to('core.workspaces.index') : null,
        ]);
    }

    public function store(
        Request $request,
        AnalyticsWorkspaceAccess $access,
        ProductAuthentication $authentication,
        PlatformCoreRouteLinks $coreLinks,
        AnalyticsDeletionFence $deletionFence,
    ): RedirectResponse {
        if ($authentication->usesCoreAuthority('analytics')) {
            $managementUrl = $coreLinks->to('core.workspaces.index');
            abort_if($managementUrl === null, 503, 'Shared workspace management is not available.');

            return redirect()->away($managementUrl);
        }

        $productUserIds = $access->productUserIds($request->user());
        abort_if($productUserIds === [], 403, 'Analytics access is not yet reconciled for this account.');
        foreach ($productUserIds as $productUserId) {
            $deletionFence->assertAccountOpen($productUserId);
        }

        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $workspace = Workspace::create(['name' => $validated['name'], 'slug' => Str::slug($validated['name']).'-'.Str::lower(Str::random(5))]);
        $workspace->users()->attach(collect($productUserIds)->mapWithKeys(
            fn (string $productUserId): array => [$productUserId => ['role' => WorkspaceRole::Owner->value]],
        )->all());
        $request->session()->put('analytics_workspace_id', $workspace->id);

        return to_route('analytics.dashboard')->with('status', 'Workspace created.');
    }

    public function select(Request $request, Workspace $workspace, AnalyticsWorkspaceAccess $access): RedirectResponse
    {
        abort_unless($access->hasAccess($request->user(), $workspace), 403);
        $request->session()->put('analytics_workspace_id', $workspace->id);

        return to_route('analytics.dashboard');
    }
}
