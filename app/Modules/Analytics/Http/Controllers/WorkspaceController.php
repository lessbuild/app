<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request, AnalyticsWorkspaceAccess $access): View
    {
        $productUserIds = $access->productUserIds($request->user());
        $workspaces = $productUserIds === []
            ? collect()
            : Workspace::query()
                ->whereHas('users', fn (Builder $query) => $query->whereIn('users.id', $productUserIds))
                ->withCount('sites')
                ->orderBy('name')
                ->get();

        return view('analytics::workspaces.index', ['workspaces' => $workspaces]);
    }

    public function store(Request $request, AnalyticsWorkspaceAccess $access): RedirectResponse
    {
        $productUserIds = $access->productUserIds($request->user());
        abort_if($productUserIds === [], 403, 'Analytics access is not yet reconciled for this account.');

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
