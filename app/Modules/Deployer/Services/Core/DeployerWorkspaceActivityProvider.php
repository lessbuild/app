<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\WorkspaceActivityProvider;
use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Data\Projects\WorkspaceActivitySnapshot;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Enums\BuildStatus;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use Carbon\CarbonImmutable;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class DeployerWorkspaceActivityProvider implements WorkspaceActivityProvider
{
    public function __construct(
        private readonly DeployerProjectLink $projectLinks,
        private readonly WorkspaceProjectAccess $projectAccess,
    ) {}

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
            $mappedEnvironments = $this->mappedEnvironments($user, $projects);

            if ($mappedEnvironments === []) {
                return new WorkspaceActivitySnapshot(collect());
            }

            $builds = Build::query()
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
                    'created_at',
                    'started_at',
                    'finished_at',
                    'updated_at',
                ]);

            return new WorkspaceActivitySnapshot($builds
                ->map(fn (Build $build): ?ProjectWorkflowRun => $this->runFor($build, $workspace, $mappedEnvironments))
                ->filter()
                ->values());
        } catch (LostConnectionException|QueryException) {
            return new WorkspaceActivitySnapshot(collect(), available: false);
        }
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
                ->get(['id', 'project_id', 'product', 'resource_type', 'resource_id', 'status', 'name']);

            foreach ($resources as $resource) {
                $environment = $this->projectLinks->accessibleEnvironment($user, $project, $resource);

                if ($environment === null || $environment->project === null) {
                    continue;
                }

                $mapped[(string) $environment->getKey()] = [
                    'project' => $project,
                    'environment' => $environment,
                    'label' => filled($resource->name) ? $resource->name : $environment->name,
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
                detail: $this->detail($status),
                state: $state,
                recordedAt: $recordedAt,
                attemptedAt: $build->started_at?->toImmutable()->utc(),
                completedAt: $build->finished_at?->toImmutable()->utc(),
                resultUrl: $resultUrl,
            )],
        );
    }

    private function detail(?BuildStatus $status): string
    {
        return match ($status) {
            BuildStatus::Queued => __('This deployment is queued. Detailed progress is not available until work begins.'),
            BuildStatus::AwaitingApproval => __('This deployment is waiting for an authorized reviewer.'),
            BuildStatus::Deploying, BuildStatus::Running => __('This deployment is active. Open its Deployer record for the latest recorded stage.'),
            BuildStatus::TimingOut => __('A remote deployment step has not reported completion. Open its Deployer record for current evidence.'),
            BuildStatus::Succeeded => __('The deployment completed successfully.'),
            BuildStatus::Failed => __('The deployment failed. Open its Deployer record for the authorized failure details.'),
            BuildStatus::Rejected => __('The deployment was declined before remote execution.'),
            BuildStatus::Canceled => __('The deployment was canceled.'),
            null => __('The stored deployment state is not recognized. Open the Deployer record to review it.'),
        };
    }
}
