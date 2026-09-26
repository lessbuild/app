<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceFeatureRolloutChange;
use App\Core\Models\WorkspaceFeatureRolloutMetric;
use App\Core\Services\WorkspaceFeatureRollouts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class WorkspaceFeatureRolloutController
{
    public function index(Request $request, Workspace $workspace, WorkspaceFeatureRollouts $rollouts): Response
    {
        $user = $this->user($request);
        $rollouts->authorize($user, $workspace, manage: true);
        $features = collect(WorkspaceFeatureRollouts::FEATURES)
            ->mapWithKeys(fn (string $feature): array => [$feature => $rollouts->state($workspace, $feature)]);

        return response()->view('core::workspaces.feature-rollouts', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => Workspace::query()->where('status', 'active')->whereNull('archived_at')
                ->whereHas('memberships', fn (Builder $query) => $query->currentlyActive()->where('user_id', $user->getKey()))
                ->orderBy('name')->get(),
            'features' => $features,
            'metrics' => WorkspaceFeatureRolloutMetric::query()->where('workspace_id', $workspace->getKey())
                ->whereIn('feature', WorkspaceFeatureRollouts::FEATURES)
                ->where('day', '>=', now()->utc()->subDays(13)->toDateString())
                ->get()->groupBy('feature'),
            'changes' => WorkspaceFeatureRolloutChange::query()->where('workspace_id', $workspace->getKey())
                ->whereIn('feature', WorkspaceFeatureRollouts::FEATURES)->with('actor:id,name')
                ->latest('created_at')->latest('id')->limit(25)->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Workspace $workspace, string $feature, WorkspaceFeatureRollouts $rollouts): RedirectResponse
    {
        $user = $this->user($request);
        $rollouts->authorize($user, $workspace, manage: true);
        $data = $request->validate(['state' => ['required', Rule::in(['default', 'enabled', 'disabled'])]]);
        $enabled = match ($data['state']) {
            'enabled' => true,
            'disabled' => false,
            default => null,
        };
        $rollouts->change($user, $workspace, $feature, $enabled);

        return redirect()->route('core.workspace.feature-rollouts.index', $workspace)
            ->with('success', __('Workspace rollout preference saved.'));
    }

    private function user(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
