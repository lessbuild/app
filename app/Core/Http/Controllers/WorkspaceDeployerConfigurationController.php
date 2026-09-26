<?php

namespace App\Core\Http\Controllers;

use App\Core\Contracts\Deployer\WorkspaceDeployerConfigurationProvider;
use App\Core\Http\Requests\Deployer\DeployerConfigurationIndexRequest;
use App\Core\Http\Requests\Deployer\DeployerProjectConfigurationPageRequest;
use App\Core\Http\Requests\Deployer\UpdateEnvironmentConfigurationRequest;
use App\Core\Http\Requests\Deployer\UpdateProjectPreviewSettingsRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\Workspace;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\WorkspaceDeployerAdministrationProviderRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WorkspaceDeployerConfigurationController
{
    public function index(
        DeployerConfigurationIndexRequest $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceDeployerAdministrationProviderRegistry $providers,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        $provider = $this->provider($providers);
        $projects = $provider->projects(
            $user,
            $workspace,
            $request->validated('q'),
            (int) $request->validated('page', 1),
        );

        return view('core.workspaces.deployer.configuration.index', [
            ...$this->pageData($user, $workspace),
            'projects' => $projects,
        ]);
    }

    public function project(
        DeployerProjectConfigurationPageRequest $request,
        Workspace $workspace,
        Project $project,
        ResolvePlatformUser $platformUsers,
        WorkspaceDeployerAdministrationProviderRegistry $providers,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        $snapshot = $this->provider($providers)->project(
            $user,
            $workspace,
            $project,
            $request->validated('environment_q'),
            (int) $request->validated('environment_page', 1),
        );

        return view('core.workspaces.deployer.configuration.project', [
            ...$this->pageData($user, $workspace, $project),
            'snapshot' => $snapshot,
        ]);
    }

    public function updateProjectPreviews(
        UpdateProjectPreviewSettingsRequest $request,
        Workspace $workspace,
        Project $project,
        ResolvePlatformUser $platformUsers,
        WorkspaceDeployerAdministrationProviderRegistry $providers,
    ): RedirectResponse {
        $user = $this->platformUser($request, $platformUsers);
        $provider = $this->provider($providers);

        try {
            $provider->updateProjectPreviews($user, $workspace, $project, $request->validated());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('core.workspace.deployer.configuration.projects.show', [$workspace, $project])
            ->with('status', __('Preview settings updated.'));
    }

    public function environment(
        Request $request,
        Workspace $workspace,
        Project $project,
        ProjectEnvironment $environment,
        ResolvePlatformUser $platformUsers,
        WorkspaceDeployerAdministrationProviderRegistry $providers,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        $snapshot = $this->provider($providers)->environment($user, $workspace, $project, $environment);

        return view('core.workspaces.deployer.configuration.environment', [
            ...$this->pageData($user, $workspace, $project),
            'snapshot' => $snapshot,
        ]);
    }

    public function updateEnvironment(
        UpdateEnvironmentConfigurationRequest $request,
        Workspace $workspace,
        Project $project,
        ProjectEnvironment $environment,
        ResolvePlatformUser $platformUsers,
        WorkspaceDeployerAdministrationProviderRegistry $providers,
    ): RedirectResponse {
        $user = $this->platformUser($request, $platformUsers);
        $provider = $this->provider($providers);

        try {
            $provider->updateEnvironment($user, $workspace, $project, $environment, $request->validated());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('core.workspace.deployer.configuration.projects.environments.show', [$workspace, $project, $environment])
            ->with('status', __('Environment settings updated.'));
    }

    private function platformUser(Request $request, ResolvePlatformUser $platformUsers): PlatformUser
    {
        $principal = $request->user('platform') ?? $request->user();
        abort_unless($principal !== null, 401);

        $user = $platformUsers->resolve($principal, 'deployer');
        abort_if($user === null, 403);

        return $user;
    }

    private function provider(WorkspaceDeployerAdministrationProviderRegistry $providers): WorkspaceDeployerConfigurationProvider
    {
        $provider = $providers->configuration();
        abort_if($provider === null, 503);

        return $provider;
    }

    /** @return array<string, mixed> */
    private function pageData(PlatformUser $user, Workspace $workspace, ?Project $project = null): array
    {
        $workspaces = Workspace::query()->where('status', 'active')->whereNull('archived_at')
            ->whereHas('memberships', fn ($query) => $query->currentlyActive()->where('user_id', $user->getKey()))
            ->orderBy('name')->get();

        return [
            'user' => $user,
            'accountUser' => $user,
            'workspace' => $workspace,
            'currentWorkspace' => $workspace,
            'workspaces' => $workspaces,
            'project' => $project,
            'contextProjects' => $project === null ? [] : [[
                'id' => (string) $project->getKey(),
                'name' => (string) $project->name,
                'href' => route('core.projects.show', [$workspace, $project]),
            ]],
            'navigation' => [],
        ];
    }
}
