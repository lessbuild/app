<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\UpdateWorkspaceCostBudgetRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Services\WorkspaceCostBreakdownProviderRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkspaceCostBreakdownController
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceCostBreakdownProviderRegistry $providers,
    ): View {
        [$user, $membership] = $this->context($request, $workspace, $access);
        $breakdowns = collect();

        foreach (['deployer', 'monitor', 'analytics'] as $product) {
            $provider = $providers->get($product);
            if ($provider === null || ! $access->hasProductAccess($membership, $product)) {
                continue;
            }

            $summary = $provider->summarize($user, $workspace);
            if ($summary !== null) {
                $breakdowns->put($product, $summary);
            }
        }

        return view('core::workspaces.costs', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => Workspace::query()
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->whereHas('memberships', fn (Builder $query) => $query->currentlyActive()->where('user_id', $user->getKey()))
                ->orderBy('name')
                ->get(),
            'breakdowns' => $breakdowns,
            'hasDeployerAccess' => $access->hasProductAccess($membership, 'deployer'),
        ]);
    }

    public function updateBudget(
        UpdateWorkspaceCostBudgetRequest $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceCostBreakdownProviderRegistry $providers,
    ): RedirectResponse {
        [$user, $membership] = $this->context($request, $workspace, $access);
        abort_unless($access->hasProductAccess($membership, 'deployer'), 403);

        $provider = $providers->get('deployer');
        abort_if($provider === null, 404);

        $amount = $request->validated('monthly_infrastructure_budget');
        abort_unless($provider->updateMonthlyBudget($user, $workspace, $amount === null ? null : (float) $amount), 403);

        return to_route('core.workspace.costs', $workspace)->with('success', __('Infrastructure budget updated.'));
    }

    /** @return array{PlatformUser, WorkspaceMembership} */
    private function context(Request $request, Workspace $workspace, WorkspaceProjectAccess $access): array
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);

        return [$user, $membership];
    }
}
