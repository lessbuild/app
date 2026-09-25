<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\Deployer\UpdateDeploymentControlsRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceDeployerAdministrationProviderRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkspaceDeployerDeploymentControlsController
{
    public function show(
        Request $request,
        Workspace $workspace,
        Project $project,
        ProjectEnvironment $environment,
        WorkspaceDeployerAdministrationProviderRegistry $providers,
    ): View {
        $user = $this->platformUser($request);
        $provider = $providers->deploymentControls();
        abort_if($provider === null, 503);
        $snapshot = $provider->snapshot($user, $workspace, $project, $environment);

        return view('core::workspaces.deployer.deployment-controls', $this->pageData($user, $workspace, $project) + compact('snapshot'));
    }

    public function update(
        UpdateDeploymentControlsRequest $request,
        Workspace $workspace,
        Project $project,
        ProjectEnvironment $environment,
        WorkspaceDeployerAdministrationProviderRegistry $providers,
    ): RedirectResponse {
        $user = $this->platformUser($request);
        $provider = $providers->deploymentControls();
        abort_if($provider === null, 503);
        $provider->update($user, $workspace, $project, $environment, $request->validated());

        return to_route('core.projects.deployer-deployment-controls.show', [$workspace, $project, $environment])
            ->with('status', __('Deployment controls updated.'));
    }

    private function platformUser(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }

    /** @return array<string, mixed> */
    private function pageData(PlatformUser $user, Workspace $workspace, Project $project): array
    {
        $workspaces = Workspace::query()->where('status', 'active')->whereNull('archived_at')
            ->whereHas('memberships', fn (Builder $query) => $query->currentlyActive()->where('user_id', $user->getKey()))
            ->orderBy('name')->get();

        return [
            'user' => $user,
            'accountUser' => $user,
            'workspace' => $workspace,
            'currentWorkspace' => $workspace,
            'workspaces' => $workspaces,
            'project' => $project,
            'contextProjects' => [[
                'id' => (string) $project->getKey(),
                'name' => (string) $project->name,
                'href' => route('core.projects.show', [$workspace, $project]),
            ]],
            'navigation' => [],
        ];
    }
}
