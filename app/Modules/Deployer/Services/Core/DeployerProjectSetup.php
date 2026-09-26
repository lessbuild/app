<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectEnvironmentAwareSetupProvider;
use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Data\Projects\ProjectSetupStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectResource;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Project as DeployerProject;
use Illuminate\Support\Facades\Route;

final class DeployerProjectSetup implements ProjectEnvironmentAwareSetupProvider
{
    public function __construct(private readonly DeployerProjectLink $projects) {}

    public function steps(PlatformUser $user, CoreProject $project): array
    {
        $legacyProject = $this->projects->projectFor($user, $project);
        $projectUrl = $legacyProject !== null ? $this->projects->urlFor($legacyProject) : null;
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

        $environmentSteps = $this->stepsForMappedEnvironments($project, $legacyProject, $projectUrl);

        if ($environmentSteps !== null) {
            return [$projectStep, ...$environmentSteps];
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

    public function stepsForEnvironment(
        PlatformUser $user,
        CoreProject $project,
        ProjectEnvironment $environment,
    ): array {
        $legacyProject = $this->projects->projectFor($user, $project);
        $coreProjectUrl = Route::has('core.projects.show')
            ? route('core.projects.show', [$project->workspace_id, $project, 'context_environment' => $environment->getKey()])
            : null;
        $mapping = ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('environment_id', $environment->getKey())
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('status', 'active')
            ->exists();

        if ($legacyProject === null) {
            return [new ProjectSetupStep(
                id: 'deployer.environment-project',
                product: 'deployer',
                title: __('Connect a Deployer project'),
                detail: __('Connect an authorized Deployer project before using this shared environment.'),
                state: ProjectSetupStepState::NeedsAction,
                url: Route::has('projects.index') ? route('projects.index') : null,
                actionLabel: Route::has('projects.index') ? __('Open Deployer') : null,
                contextName: $environment->name,
                contextLabel: __('Environment'),
            )];
        }

        if (! $mapping) {
            return [new ProjectSetupStep(
                id: 'deployer.environment-mapping',
                product: 'deployer',
                title: __('Map a Deployer environment'),
                detail: __('Choose an existing Deployer environment to connect it to this shared environment. Deployment status stays unavailable until the mapping is confirmed.'),
                state: ProjectSetupStepState::NeedsAction,
                url: $coreProjectUrl === null ? null : $coreProjectUrl.'#link-existing-resources-heading',
                actionLabel: $coreProjectUrl === null ? null : __('Review environment mapping'),
                contextName: $environment->name,
                contextLabel: __('Environment'),
            )];
        }

        $projectUrl = $this->projects->urlFor($legacyProject);
        $environmentSteps = $this->stepsForMappedEnvironments($project, $legacyProject, $projectUrl, (string) $environment->getKey()) ?? [];

        return [
            new ProjectSetupStep(
                id: 'deployer.project.environment.'.$environment->getKey(),
                product: 'deployer',
                title: __('Deployer project connected'),
                detail: __('This Deployer project is authorized for the shared workspace.'),
                state: ProjectSetupStepState::Complete,
                url: $projectUrl,
                actionLabel: $projectUrl === null ? null : __('Open project'),
                contextName: $environment->name,
                contextLabel: __('Environment'),
            ),
            ...$environmentSteps,
        ];
    }

    /**
     * @return ?list<ProjectSetupStep> Null when the project has no explicit Deployer environment mappings.
     */
    private function stepsForMappedEnvironments(
        CoreProject $project,
        DeployerProject $legacyProject,
        ?string $projectUrl,
        ?string $canonicalEnvironmentId = null,
    ): ?array {
        $mappings = ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('status', 'active')
            ->whereNotNull('environment_id')
            ->when($canonicalEnvironmentId !== null, fn ($query) => $query->where('environment_id', $canonicalEnvironmentId))
            ->orderBy('id')
            ->get(['id', 'environment_id', 'resource_id', 'name']);

        if ($mappings->isEmpty()) {
            return null;
        }

        $canonicalEnvironments = ProjectEnvironment::query()
            ->where('project_id', $project->getKey())
            ->whereIn('id', $mappings->pluck('environment_id')->unique())
            ->get(['id', 'name'])
            ->keyBy(fn (ProjectEnvironment $environment): string => (string) $environment->getKey());
        $sourceEnvironments = Environment::query()
            ->where('project_id', $legacyProject->getKey())
            ->whereIn('id', $mappings->pluck('resource_id')->unique())
            ->get(['id', 'project_id', 'name'])
            ->keyBy(fn (Environment $environment): string => (string) $environment->getKey());

        return $mappings->map(function (ProjectResource $mapping) use ($canonicalEnvironments, $sourceEnvironments, $projectUrl, $legacyProject): ProjectSetupStep {
            $environmentId = (string) $mapping->environment_id;
            $canonicalEnvironment = $canonicalEnvironments->get($environmentId);
            $sourceEnvironment = $sourceEnvironments->get((string) $mapping->resource_id);

            if ($canonicalEnvironment === null || $sourceEnvironment === null) {
                return new ProjectSetupStep(
                    id: 'deployer.environment.'.$mapping->getKey(),
                    product: 'deployer',
                    title: __('Review environment mapping'),
                    detail: __('The linked Deployer environment is no longer available. Review the project environment mapping before relying on setup status.'),
                    state: ProjectSetupStepState::NeedsAction,
                    url: $projectUrl,
                    actionLabel: $projectUrl === null ? null : __('Review project'),
                    contextName: $canonicalEnvironment?->name ?? $mapping->name,
                    contextLabel: __('Environment'),
                );
            }

            $repositoryConnected = Environment::query()
                ->whereKey($sourceEnvironment->getKey())
                ->where('project_id', $legacyProject->getKey())
                ->whereHas('website.repositories')
                ->exists();
            $latestBuild = Build::query()
                ->where('environment_id', $sourceEnvironment->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['id', 'status']);
            $deploymentSucceeded = $latestBuild?->status === Build::STATUS_SUCCEEDED;
            $environmentUrl = $projectUrl === null
                ? null
                : $projectUrl.'#environment-'.$sourceEnvironment->getKey();

            return new ProjectSetupStep(
                id: 'deployer.repository.'.$mapping->getKey(),
                product: 'deployer',
                title: $repositoryConnected ? __('Source repository connected') : __('Connect a source repository'),
                detail: $repositoryConnected
                    ? __('A repository is connected to this Deployer environment.')
                    : __('Connect a repository to this environment before deploying.'),
                state: $repositoryConnected ? ProjectSetupStepState::Complete : ProjectSetupStepState::NeedsAction,
                url: $repositoryConnected ? null : $environmentUrl,
                actionLabel: $repositoryConnected ? null : __('Open environment'),
                contextName: $canonicalEnvironment->name,
                contextLabel: __('Environment'),
            );
        })->concat($mappings->map(function (ProjectResource $mapping) use ($canonicalEnvironments, $sourceEnvironments, $projectUrl): ?ProjectSetupStep {
            $sourceEnvironment = $sourceEnvironments->get((string) $mapping->resource_id);
            $canonicalEnvironment = $canonicalEnvironments->get((string) $mapping->environment_id);

            if ($canonicalEnvironment === null || $sourceEnvironment === null) {
                return null;
            }

            $latestBuild = Build::query()
                ->where('environment_id', $sourceEnvironment->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['id', 'status']);
            $deploymentSucceeded = $latestBuild?->status === Build::STATUS_SUCCEEDED;

            return new ProjectSetupStep(
                id: 'deployer.deployment.'.$mapping->getKey(),
                product: 'deployer',
                title: __('Complete a deployment'),
                detail: $deploymentSucceeded
                    ? __('The latest deployment completed successfully for this environment.')
                    : ($latestBuild === null
                        ? __('No deployment has completed for this environment yet.')
                        : __('The latest deployment for this environment is :status.', ['status' => str($latestBuild->status)->headline()])),
                state: $deploymentSucceeded ? ProjectSetupStepState::Complete : ProjectSetupStepState::NeedsAction,
                url: $deploymentSucceeded || $projectUrl === null
                    ? null
                    : $projectUrl.'#environment-'.$sourceEnvironment->getKey(),
                actionLabel: $deploymentSucceeded || $projectUrl === null ? null : __('Open environment'),
                contextName: $canonicalEnvironment->name,
                contextLabel: __('Environment'),
            );
        }))->filter()->values()->all();
    }
}
