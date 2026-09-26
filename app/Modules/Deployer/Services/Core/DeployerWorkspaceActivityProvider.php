<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\WorkspaceActivityProvider;
use App\Core\Contracts\WorkspaceWebhookDeliveryProvider;
use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Data\Projects\WorkspaceActivitySnapshot;
use App\Core\Data\Projects\WorkspaceWebhookDeliverySnapshot;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Enums\BuildStatus;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Services\RepositoryDeploymentPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class DeployerWorkspaceActivityProvider implements WorkspaceActivityProvider, WorkspaceWebhookDeliveryProvider
{
    public function __construct(
        private readonly DeployerProjectLink $projectLinks,
        private readonly WorkspaceProjectAccess $projectAccess,
        private readonly DeployerProvisioningActivity $provisioningActivity,
        private readonly DeployerBackupActivity $backupActivity,
        private readonly DeployerOperationalActivity $operationalActivity,
        private readonly DeployerRepositoryWebhookActivity $repositoryWebhookActivity,
        private readonly RepositoryDeploymentPlan $deploymentPlan,
        private readonly DeployerAlertDeliveryActivity $alertDeliveryActivity,
    ) {}

    /**
     * @param  Collection<int, CoreProject>  $projects
     */
    public function recentWebhookDeliveriesForWorkspace(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        int $limit,
    ): WorkspaceWebhookDeliverySnapshot {
        $projects = $projects
            ->filter(fn (CoreProject $project): bool => (string) $project->workspace_id === (string) $workspace->getKey())
            ->values();

        if ($projects->isEmpty()) {
            return new WorkspaceWebhookDeliverySnapshot(collect());
        }

        try {
            $mappedEnvironments = $this->mappedEnvironments($user, $projects);

            return new WorkspaceWebhookDeliverySnapshot(
                $this->repositoryWebhookActivity->deliveryHistoryForMappedEnvironments($mappedEnvironments, $limit)
                    ->concat($this->alertDeliveryActivity->deliveryHistoryForMappedEnvironments($mappedEnvironments, $limit))
                    ->sortByDesc(fn ($delivery): int => $delivery->recordedAt->getTimestamp())
                    ->take(max(1, min(100, $limit)))
                    ->values(),
            );
        } catch (LostConnectionException|QueryException) {
            return new WorkspaceWebhookDeliverySnapshot(collect(), available: false);
        }
    }

    /**
     * @param  Collection<int, CoreProject>  $projects
     */
    public function recentForWorkspace(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        int $limit,
    ): WorkspaceActivitySnapshot {
        if ($projects->isEmpty()) {
            return new WorkspaceActivitySnapshot(collect());
        }

        try {
            $mappedProjects = $this->mappedProjects($user, $workspace, $projects);
            $mappedEnvironments = $this->mappedEnvironments($user, $projects);

            if ($mappedEnvironments === [] && $mappedProjects === []) {
                return new WorkspaceActivitySnapshot(collect());
            }

            $builds = $mappedEnvironments === []
                ? collect()
                : Build::query()
                    ->whereIn('environment_id', array_keys($mappedEnvironments))
                    ->with('environment:id,project_id,name')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(max(1, min(100, $limit)))
                    ->get([
                        'id',
                        'environment_id',
                        'status',
                        'revision',
                        'release_name',
                        'setup_stage',
                        'created_at',
                        'started_at',
                        'finished_at',
                        'updated_at',
                    ]);

            $runs = $builds
                ->map(fn (Build $build): ?ProjectWorkflowRun => $this->runFor($build, $workspace, $mappedEnvironments))
                ->filter()
                ->concat($this->provisioningActivity->forMappedEnvironments($mappedEnvironments, $workspace, $limit))
                ->concat($this->backupActivity->forMappedEnvironments($mappedEnvironments, $workspace, $limit))
                ->concat($this->operationalActivity->forMappedEnvironments($mappedEnvironments, $mappedProjects, $workspace, $limit))
                ->sortByDesc(fn (ProjectWorkflowRun $run): int => $run->recordedAt->getTimestamp())
                ->take(max(1, min(100, $limit)))
                ->values();

            return new WorkspaceActivitySnapshot($runs);
        } catch (LostConnectionException|QueryException) {
            return new WorkspaceActivitySnapshot(collect(), available: false);
        }
    }

    /**
     * @param  Collection<int, CoreProject>  $projects
     * @return array<string, array{project: CoreProject, deployer_project: Project, organization_id: int}>
     */
    private function mappedProjects(PlatformUser $user, Workspace $workspace, Collection $projects): array
    {
        $mapped = [];
        $ambiguous = [];

        foreach ($projects as $project) {
            if ((string) $project->workspace_id !== (string) $workspace->getKey()
                || ! $this->projectAccess->canAccessProductResource($user, $project, 'deployer')) {
                continue;
            }

            $resources = ProjectResource::query()
                ->where('project_id', $project->getKey())
                ->where('product', 'deployer')
                ->where('resource_type', 'project')
                ->where('status', 'active')
                ->orderBy('id')
                ->get(['resource_id']);

            if ($resources->count() !== 1) {
                continue;
            }

            $resourceId = (string) $resources->first()->resource_id;
            $deployerProject = $this->projectLinks->projectFor($user, $project);

            if ($deployerProject === null || (string) $deployerProject->getKey() !== $resourceId) {
                continue;
            }

            $key = (string) $deployerProject->getKey();
            if (isset($ambiguous[$key])) {
                continue;
            }

            if (isset($mapped[$key]) && (string) $mapped[$key]['project']->getKey() !== (string) $project->getKey()) {
                unset($mapped[$key]);
                $ambiguous[$key] = true;

                continue;
            }

            $mapped[$key] = [
                'project' => $project,
                'deployer_project' => $deployerProject,
                'organization_id' => (int) $deployerProject->organization_id,
            ];
        }

        return $mapped;
    }

    /**
     * @param  Collection<int, CoreProject>  $projects
     * @return array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>
     */
    private function mappedEnvironments(PlatformUser $user, Collection $projects): array
    {
        $mapped = [];

        foreach ($projects as $project) {
            if (! $this->projectAccess->canAccessProductResource($user, $project, 'deployer')) {
                continue;
            }

            $resources = ProjectResource::query()
                ->where('project_id', $project->getKey())
                ->where('product', 'deployer')
                ->where('resource_type', 'environment')
                ->where('status', 'active')
                ->orderBy('id')
                ->with('environment:id,name')
                ->get(['id', 'project_id', 'product', 'resource_type', 'resource_id', 'status', 'name', 'environment_id']);

            foreach ($resources as $resource) {
                $environment = $this->projectLinks->accessibleEnvironment($user, $project, $resource);

                if ($environment === null || $environment->project === null) {
                    continue;
                }

                $mapped[(string) $environment->getKey()] = [
                    'project' => $project,
                    'environment' => $environment,
                    'label' => filled($resource->name) ? $resource->name : $environment->name,
                    'canonical_environment_id' => $resource->environment_id === null ? null : (string) $resource->environment_id,
                    'canonical_environment_name' => $resource->environment?->name,
                    'organization_id' => (int) $environment->project->organization_id,
                ];
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     */
    private function runFor(Build $build, Workspace $workspace, array $mappedEnvironments): ?ProjectWorkflowRun
    {
        $mapping = $mappedEnvironments[(string) $build->environment_id] ?? null;

        if ($mapping === null || $build->environment?->project_id === null) {
            return null;
        }

        $project = $mapping['project'];
        $status = $build->statusEnum();
        $revision = filled($build->revision)
            ? str($build->revision)->limit(12, '')
            : ($build->release_name ?: __('Build #:id', ['id' => $build->getKey()]));
        $title = __('Deployment :version', ['version' => $revision]);
        $recordedAt = $build->created_at?->toImmutable()->utc() ?? CarbonImmutable::now('UTC');

        $resultUrl = Route::has('builds.show')
            ? route('builds.show', [
                'build' => $build->getKey(),
                'organization_id' => $mapping['organization_id'],
            ])
            : null;

        $state = match ($status) {
            BuildStatus::Queued => ProjectWorkflowStepState::Pending,
            BuildStatus::AwaitingApproval => ProjectWorkflowStepState::AwaitingApproval,
            BuildStatus::Deploying, BuildStatus::Running, BuildStatus::TimingOut => ProjectWorkflowStepState::Processing,
            BuildStatus::Succeeded => ProjectWorkflowStepState::Succeeded,
            BuildStatus::Failed, BuildStatus::Rejected => ProjectWorkflowStepState::Failed,
            BuildStatus::Canceled => ProjectWorkflowStepState::Discarded,
            null => ProjectWorkflowStepState::Unknown,
        };

        return new ProjectWorkflowRun(
            key: 'deployer:build:'.$build->getKey(),
            title: $title,
            recordedAt: $recordedAt,
            projectId: (string) $project->getKey(),
            projectName: $project->name,
            projectUrl: route('core.projects.show', [$workspace, $project]),
            steps: [new ProjectWorkflowStep(
                product: 'deployer',
                productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                title: __(':environment deployment', ['environment' => $mapping['label']]),
                detail: $this->detail($status, (int) $build->setup_stage),
                state: $state,
                recordedAt: $recordedAt,
                attemptedAt: $build->started_at?->toImmutable()->utc(),
                completedAt: $build->finished_at?->toImmutable()->utc(),
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'],
                environmentName: $mapping['canonical_environment_name'] ?: $mapping['label'],
            )],
        );
    }

    private function detail(?BuildStatus $status, int $setupStage): string
    {
        $finalStage = $this->deploymentPlan->finalStage();
        $completedStages = $status === BuildStatus::Succeeded
            ? $finalStage
            : max(0, min($finalStage, $setupStage));
        $progress = ['completed' => $completedStages, 'total' => $finalStage];

        return match ($status) {
            BuildStatus::Queued => __('This deployment is queued. :completed of :total stages are recorded.', $progress),
            BuildStatus::AwaitingApproval => __('This deployment is waiting for an authorized reviewer.'),
            BuildStatus::Deploying, BuildStatus::Running => __('This deployment is active. :completed of :total stages are recorded.', $progress),
            BuildStatus::TimingOut => __('A remote deployment step has not reported completion. :completed of :total stages are recorded.', $progress),
            BuildStatus::Succeeded => __('The deployment completed successfully. :completed of :total stages are recorded.', $progress),
            BuildStatus::Failed => __('The deployment failed. :completed of :total stages are recorded. Open its Deployer record for the authorized failure details.', $progress),
            BuildStatus::Rejected => __('The deployment was declined before remote execution.'),
            BuildStatus::Canceled => __('The deployment was canceled.'),
            null => __('The stored deployment state is not recognized. Open the Deployer record to review it.'),
        };
    }
}
