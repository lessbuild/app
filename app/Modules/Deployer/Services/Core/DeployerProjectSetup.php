<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectSetupProvider;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use Illuminate\Support\Facades\Route;

final class DeployerProjectSetup implements ProjectSetupProvider
{
    public function __construct(private readonly DeployerProjectLink $projects) {}

    public function steps(PlatformUser $user, CoreProject $project): array
    {
        $legacyProject = $this->projects->projectFor($user, $project);
        $projectUrl = $legacyProject !== null && Route::has('projects.show')
            ? route('projects.show', $legacyProject->getKey())
            : null;
        $projectIndexUrl = Route::has('projects.index') ? route('projects.index') : null;

        $projectStep = new ProjectSetupStep(
            id: 'deployer.project',
            product: 'deployer',
            title: __('Connect a Deployer project'),
            detail: $legacyProject !== null
                ? __('The existing Deployer project is linked to this shared project.')
                : __('Open Deployer to choose an existing project or create one. Imported projects can be linked without recreating their resources.'),
            state: $legacyProject !== null ? ProjectSetupStepState::Complete : ProjectSetupStepState::NeedsAction,
            url: $projectUrl ?? $projectIndexUrl,
            actionLabel: $projectUrl !== null ? __('Open project') : __('Open Deployer'),
        );

        if ($legacyProject === null) {
            return [
                $projectStep,
                new ProjectSetupStep(
                    id: 'deployer.repository',
                    product: 'deployer',
                    title: __('Connect a source repository'),
                    detail: __('Link a Deployer project first. Its existing environments and repositories will be recognized.'),
                    state: ProjectSetupStepState::NeedsAction,
                ),
                new ProjectSetupStep(
                    id: 'deployer.deployment',
                    product: 'deployer',
                    title: __('Complete a deployment'),
                    detail: __('Link a Deployer project and repository to start the first deployment.'),
                    state: ProjectSetupStepState::NeedsAction,
                ),
            ];
        }

        $repositoryConnected = Environment::query()
            ->where('project_id', $legacyProject->getKey())
            ->whereHas('website.repositories')
            ->exists();
        $latestBuild = Build::query()
            ->whereHas('environment', fn ($query) => $query->where('project_id', $legacyProject->getKey()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'status']);
        $deploymentSucceeded = $latestBuild?->status === Build::STATUS_SUCCEEDED;

        return [
            $projectStep,
            new ProjectSetupStep(
                id: 'deployer.repository',
                product: 'deployer',
                title: __('Connect a source repository'),
                detail: $repositoryConnected
                    ? __('A repository is connected to an environment in this project.')
                    : __('Connect an existing repository to one of this project’s environments.'),
                state: $repositoryConnected ? ProjectSetupStepState::Complete : ProjectSetupStepState::NeedsAction,
                url: $projectUrl,
                actionLabel: $repositoryConnected ? null : __('Open project'),
            ),
            new ProjectSetupStep(
                id: 'deployer.deployment',
                product: 'deployer',
                title: __('Complete a deployment'),
                detail: $deploymentSucceeded
                    ? __('The latest deployment completed successfully.')
                    : ($latestBuild === null
                        ? __('No deployment has completed for this project yet.')
                        : __('The latest deployment is :status.', ['status' => str($latestBuild->status)->headline()])),
                state: $deploymentSucceeded ? ProjectSetupStepState::Complete : ProjectSetupStepState::NeedsAction,
                url: $projectUrl,
                actionLabel: $deploymentSucceeded ? null : __('Open project'),
            ),
        ];
    }
}
