<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\PreviewDeployment;
use App\Modules\Deployer\Models\PreviewStackCleanup;
use App\Modules\Deployer\Models\Project as DeployerProject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Read authorized preview and cleanup task state through explicit Deployer project mappings. */
final class DeployerPreviewActivity
{
    public function __construct(private readonly DeployerProjectLink $projectLinks) {}

    /**
     * @param  array<string, array{project: CoreProject, deployer_project: DeployerProject, organization_id: int}>  $mappedProjects
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forMappedProjects(array $mappedProjects, Workspace $workspace, int $limit): Collection
    {
        if ($mappedProjects === [] || ! Schema::connection('deployer')->hasTable('preview_deployments')) {
            return collect();
        }

        $resultLimit = max(1, min(100, $limit));
        $cutoff = CarbonImmutable::now('UTC')->subDays(30);
        $hasInitializationTracking = Schema::connection('deployer')->hasColumn('preview_deployments', 'initialization_status');
        $previewColumns = [
            'id',
            'project_id',
            'pull_request_number',
            'status',
            'last_activity_at',
            'closed_at',
            'created_at',
            'updated_at',
        ];

        if ($hasInitializationTracking) {
            $previewColumns = [
                ...$previewColumns,
                'initialization_status',
                'initialization_attempts',
                'initialization_completed_at',
            ];
        }

        $previews = PreviewDeployment::query()
            ->whereIn('project_id', array_keys($mappedProjects))
            ->where(fn ($query) => $query
                ->whereIn('status', [PreviewDeployment::STATUS_PROVISIONING, PreviewDeployment::STATUS_DEPLOYING])
                ->orWhere('updated_at', '>=', $cutoff))
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->limit($resultLimit)
            ->get($previewColumns);

        $runs = $previews->flatMap(function (PreviewDeployment $preview) use ($mappedProjects, $workspace, $hasInitializationTracking): array {
            $mapping = $mappedProjects[(string) $preview->project_id] ?? null;
            if ($mapping === null) {
                return [];
            }

            $runs = [$this->previewRun($preview, $mapping, $workspace)];
            if ($hasInitializationTracking && $preview->initialization_status !== PreviewDeployment::INITIALIZATION_NOT_CONFIGURED) {
                $runs[] = $this->initializationRun($preview, $mapping, $workspace);
            }

            return $runs;
        });

        if (Schema::connection('deployer')->hasTable('preview_stack_cleanups')) {
            $cleanups = PreviewStackCleanup::query()
                ->whereHas('previewDeployment', fn ($query) => $query->whereIn('project_id', array_keys($mappedProjects)))
                ->with('previewDeployment:id,project_id,pull_request_number')
                ->where(fn ($query) => $query
                    ->whereIn('status', [PreviewStackCleanup::STATUS_QUEUED, PreviewStackCleanup::STATUS_RUNNING])
                    ->orWhere('updated_at', '>=', $cutoff))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($resultLimit)
                ->get(['id', 'preview_deployment_id', 'status', 'started_at', 'completed_at', 'created_at', 'updated_at']);

            foreach ($cleanups as $cleanup) {
                $mapping = $mappedProjects[(string) $cleanup->previewDeployment?->project_id] ?? null;
                if ($mapping === null || $cleanup->previewDeployment === null) {
                    continue;
                }

                $runs->push($this->cleanupRun($cleanup, $mapping, $workspace));
            }
        }

        return $runs->sortByDesc(fn (ProjectWorkflowRun $run): int => $run->recordedAt->getTimestamp())
            ->take($resultLimit)
            ->values();
    }

    /**
     * @param  array{project: CoreProject, deployer_project: DeployerProject, organization_id: int}  $mapping
     */
    private function previewRun(PreviewDeployment $preview, array $mapping, Workspace $workspace): ProjectWorkflowRun
    {
        $state = match ($preview->status) {
            PreviewDeployment::STATUS_PROVISIONING => ProjectWorkflowStepState::Pending,
            PreviewDeployment::STATUS_DEPLOYING => ProjectWorkflowStepState::Processing,
            PreviewDeployment::STATUS_READY => ProjectWorkflowStepState::Succeeded,
            PreviewDeployment::STATUS_FAILED => ProjectWorkflowStepState::Failed,
            PreviewDeployment::STATUS_CLOSED => ProjectWorkflowStepState::Discarded,
            default => ProjectWorkflowStepState::Unknown,
        };
        $recordedAt = $preview->last_activity_at?->toImmutable()->utc()
            ?? $preview->updated_at?->toImmutable()->utc()
            ?? $preview->created_at?->toImmutable()->utc()
            ?? CarbonImmutable::now('UTC');

        return $this->run(
            key: 'deployer:preview:'.$preview->getKey(),
            title: __('Preview deployment · PR #:number', ['number' => $preview->pull_request_number]),
            detailTitle: __('Preview environment'),
            detail: match ($state) {
                ProjectWorkflowStepState::Pending => __('The preview environment is being provisioned.'),
                ProjectWorkflowStepState::Processing => __('The preview deployment is in progress.'),
                ProjectWorkflowStepState::Succeeded => __('The preview environment is ready.'),
                ProjectWorkflowStepState::Failed => __('The preview deployment failed. Open Deployer for authorized details.'),
                ProjectWorkflowStepState::Discarded => __('The preview environment was closed.'),
                default => __('The preview deployment state is unavailable. Open Deployer for current information.'),
            },
            state: $state,
            recordedAt: $recordedAt,
            attemptedAt: null,
            completedAt: $state === ProjectWorkflowStepState::Discarded
                ? $preview->closed_at?->toImmutable()->utc()
                : null,
            mapping: $mapping,
            workspace: $workspace,
        );
    }

    /**
     * @param  array{project: CoreProject, deployer_project: DeployerProject, organization_id: int}  $mapping
     */
    private function initializationRun(PreviewDeployment $preview, array $mapping, Workspace $workspace): ProjectWorkflowRun
    {
        $status = (string) $preview->initialization_status;
        $state = match ($status) {
            PreviewDeployment::INITIALIZATION_PENDING => ProjectWorkflowStepState::Pending,
            PreviewDeployment::INITIALIZATION_RUNNING => ProjectWorkflowStepState::Processing,
            PreviewDeployment::INITIALIZATION_SUCCEEDED => ProjectWorkflowStepState::Succeeded,
            PreviewDeployment::INITIALIZATION_FAILED => ProjectWorkflowStepState::Failed,
            default => ProjectWorkflowStepState::Unknown,
        };
        $recordedAt = $preview->initialization_completed_at?->toImmutable()->utc()
            ?? $preview->last_activity_at?->toImmutable()->utc()
            ?? $preview->updated_at?->toImmutable()->utc()
            ?? CarbonImmutable::now('UTC');

        return $this->run(
            key: 'deployer:preview-initialization:'.$preview->getKey(),
            title: __('Preview initialization · PR #:number', ['number' => $preview->pull_request_number]),
            detailTitle: __('Preview setup'),
            detail: match ($state) {
                ProjectWorkflowStepState::Pending => __('Preview setup is queued. :attempts attempts have been recorded.', ['attempts' => min(999, max(0, (int) $preview->initialization_attempts))]),
                ProjectWorkflowStepState::Processing => __('Preview setup is running. :attempts attempts have been recorded.', ['attempts' => min(999, max(0, (int) $preview->initialization_attempts))]),
                ProjectWorkflowStepState::Succeeded => __('Preview setup completed successfully.'),
                ProjectWorkflowStepState::Failed => __('Preview setup failed. Open Deployer for authorized details.'),
                default => __('The preview setup state is unavailable. Open Deployer for current information.'),
            },
            state: $state,
            recordedAt: $recordedAt,
            attemptedAt: null,
            completedAt: $preview->initialization_completed_at?->toImmutable()->utc(),
            mapping: $mapping,
            workspace: $workspace,
        );
    }

    /**
     * @param  array{project: CoreProject, deployer_project: DeployerProject, organization_id: int}  $mapping
     */
    private function cleanupRun(PreviewStackCleanup $cleanup, array $mapping, Workspace $workspace): ProjectWorkflowRun
    {
        $state = match ($cleanup->status) {
            PreviewStackCleanup::STATUS_QUEUED => ProjectWorkflowStepState::Pending,
            PreviewStackCleanup::STATUS_RUNNING => ProjectWorkflowStepState::Processing,
            PreviewStackCleanup::STATUS_SUCCEEDED => ProjectWorkflowStepState::Succeeded,
            PreviewStackCleanup::STATUS_FAILED => ProjectWorkflowStepState::Failed,
            default => ProjectWorkflowStepState::Unknown,
        };
        $number = $cleanup->previewDeployment->pull_request_number;
        $recordedAt = $cleanup->created_at?->toImmutable()->utc()
            ?? $cleanup->updated_at?->toImmutable()->utc()
            ?? CarbonImmutable::now('UTC');

        return $this->run(
            key: 'deployer:preview-cleanup:'.$cleanup->getKey(),
            title: __('Preview stack cleanup · PR #:number', ['number' => $number]),
            detailTitle: __('Preview stack cleanup'),
            detail: match ($state) {
                ProjectWorkflowStepState::Pending => __('Preview stack cleanup is queued.'),
                ProjectWorkflowStepState::Processing => __('Preview stack cleanup is running.'),
                ProjectWorkflowStepState::Succeeded => __('Preview stack cleanup completed successfully.'),
                ProjectWorkflowStepState::Failed => __('Preview stack cleanup failed. Open Deployer to retry or review authorized details.'),
                default => __('The preview stack cleanup state is unavailable. Open Deployer for current information.'),
            },
            state: $state,
            recordedAt: $recordedAt,
            attemptedAt: $cleanup->started_at?->toImmutable()->utc(),
            completedAt: $cleanup->completed_at?->toImmutable()->utc(),
            mapping: $mapping,
            workspace: $workspace,
        );
    }

    /**
     * @param  array{project: CoreProject, deployer_project: DeployerProject, organization_id: int}  $mapping
     */
    private function run(
        string $key,
        string $title,
        string $detailTitle,
        string $detail,
        ProjectWorkflowStepState $state,
        CarbonImmutable $recordedAt,
        ?CarbonImmutable $attemptedAt,
        ?CarbonImmutable $completedAt,
        array $mapping,
        Workspace $workspace,
    ): ProjectWorkflowRun {
        $coreProject = $mapping['project'];

        return new ProjectWorkflowRun(
            key: $key,
            title: $title,
            recordedAt: $recordedAt,
            projectId: (string) $coreProject->getKey(),
            projectName: $coreProject->name,
            projectUrl: route('core.projects.show', [$workspace, $coreProject]),
            steps: [new ProjectWorkflowStep(
                product: 'deployer',
                productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                title: $detailTitle,
                detail: $detail,
                state: $state,
                recordedAt: $recordedAt,
                attemptedAt: $attemptedAt,
                completedAt: $completedAt,
                resultUrl: $this->projectLinks->urlFor($mapping['deployer_project'], 'preview-environments'),
            )],
        );
    }
}
