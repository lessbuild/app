<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\ConfigurationOperation;
use App\Modules\Deployer\Models\DatabaseClone;
use App\Modules\Deployer\Models\DatabaseOperationRun;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\ScheduledTaskRun;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerCommandExecution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Read bounded Deployer operational task state through explicitly mapped environments. */
final class DeployerOperationalActivity
{
    public function __construct(
        private readonly DeployerPreviewActivity $previewActivity,
        private readonly DeployerRepositoryWebhookActivity $repositoryWebhookActivity,
        private readonly DeployerServerDiagnosticActivity $serverDiagnosticActivity,
        private readonly DeployerLogSnapshotActivity $logSnapshotActivity,
        private readonly DeployerWebsiteHealthCheckActivity $websiteHealthCheckActivity,
        private readonly DeployerWebsiteDomainActivity $websiteDomainActivity,
        private readonly DeployerDeploymentObservationActivity $deploymentObservationActivity,
        private readonly DeployerLoadBalancerActivity $loadBalancerActivity,
    ) {}

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @param  array<string, array{project: CoreProject, deployer_project: Project, organization_id: int}>  $mappedProjects
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forMappedEnvironments(array $mappedEnvironments, array $mappedProjects, Workspace $workspace, int $limit): Collection
    {
        $queryLimit = max(1, min(100, $limit));
        $previewRuns = $this->previewActivity->forMappedProjects($mappedProjects, $workspace, $queryLimit);

        if ($mappedEnvironments === []) {
            return $previewRuns;
        }

        return $previewRuns
            ->concat($this->repositoryWebhookActivity->forMappedEnvironments($mappedEnvironments, $workspace, $queryLimit))
            ->concat($this->serverDiagnosticActivity->forMappedEnvironments($mappedEnvironments, $workspace, $queryLimit))
            ->concat($this->logSnapshotActivity->forMappedEnvironments($mappedEnvironments, $workspace, $queryLimit))
            ->concat($this->websiteHealthCheckActivity->forMappedEnvironments($mappedEnvironments, $workspace, $queryLimit))
            ->concat($this->websiteDomainActivity->forMappedEnvironments($mappedEnvironments, $workspace, $queryLimit))
            ->concat($this->deploymentObservationActivity->forMappedEnvironments($mappedEnvironments, $workspace, $queryLimit))
            ->concat($this->loadBalancerActivity->forMappedEnvironments($mappedEnvironments, $workspace, $queryLimit))
            ->concat($this->scheduledTaskRuns($mappedEnvironments, $workspace, $queryLimit))
            ->concat($this->configurationOperations($mappedEnvironments, $workspace, $queryLimit))
            ->concat($this->databaseClones($mappedEnvironments, $workspace, $queryLimit))
            ->concat($this->databaseOperations($mappedEnvironments, $workspace, $queryLimit))
            ->concat($this->serverCommands($mappedEnvironments, $workspace, $queryLimit))
            ->sortByDesc(fn (ProjectWorkflowRun $run): int => $run->recordedAt->getTimestamp())
            ->take($queryLimit)
            ->values();
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappings
     * @return Collection<int, ProjectWorkflowRun>
     */
    private function configurationOperations(array $mappings, Workspace $workspace, int $limit): Collection
    {
        if (! Schema::connection('deployer')->hasTable('configuration_operations')
            || ! Schema::connection('deployer')->hasTable('configuration_applications')
            || ! Schema::connection('deployer')->hasTable('configuration_reviews')) {
            return collect();
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays(30);

        return ConfigurationOperation::query()
            ->whereIn('environment_id', array_keys($mappings))
            // Once a build exists, the build provider is the canonical activity
            // row for that deployment. Keep this projection to the durable
            // configuration-delivery phase before a build has been reserved.
            ->whereNull('build_id')
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('status', [
                    'pending',
                    'delivering',
                    'delivery_failed',
                    'delivered',
                    'blocked',
                    'awaiting_approval',
                ])->orWhere(function ($terminal) use ($cutoff): void {
                    $terminal->whereIn('status', ['succeeded', 'failed', 'canceled'])
                        ->where('updated_at', '>=', $cutoff);
                });
            })
            ->with('application.review:id,project_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'configuration_application_id',
                'environment_id',
                'environment_slug',
                'kind',
                'status',
                'build_id',
                'started_at',
                'completed_at',
                'created_at',
                'updated_at',
            ])
            ->map(function (ConfigurationOperation $operation) use ($mappings, $workspace): ?ProjectWorkflowRun {
                $mapping = $mappings[(string) $operation->environment_id] ?? null;
                $reviewProjectId = $operation->application?->review?->project_id;

                if ($mapping === null
                    || $reviewProjectId === null
                    || (string) $reviewProjectId !== (string) $mapping['environment']->project_id) {
                    return null;
                }

                return $this->run(
                    key: 'deployer:configuration-operation:'.$operation->getKey(),
                    title: __('Configuration delivery'),
                    productTitle: __(':environment configuration update', ['environment' => $mapping['label']]),
                    status: (string) $operation->status,
                    recordedAt: $operation->created_at,
                    attemptedAt: $operation->started_at,
                    completedAt: $operation->completed_at,
                    resultUrl: Route::has('projects.show')
                        ? route('projects.show', [
                            'project' => $mapping['environment']->project_id,
                            'organization_id' => $mapping['organization_id'],
                        ])
                        : null,
                    mapping: $mapping,
                    workspace: $workspace,
                    detailRoute: __('the Deployer project'),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappings
     * @return Collection<int, ProjectWorkflowRun>
     */
    private function scheduledTaskRuns(array $mappings, Workspace $workspace, int $limit): Collection
    {
        if (! Schema::connection('deployer')->hasTable('scheduled_task_runs')
            || ! Schema::connection('deployer')->hasTable('scheduled_tasks')) {
            return collect();
        }

        return ScheduledTaskRun::query()
            ->whereHas('task', fn ($query) => $query->whereIn('environment_id', array_keys($mappings)))
            ->with('task:id,environment_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'scheduled_task_id', 'status', 'started_at', 'finished_at', 'created_at', 'updated_at'])
            ->map(function (ScheduledTaskRun $taskRun) use ($mappings, $workspace): ?ProjectWorkflowRun {
                $mapping = $mappings[(string) $taskRun->task?->environment_id] ?? null;

                return $mapping === null ? null : $this->run(
                    key: 'deployer:scheduled-task:'.$taskRun->getKey(),
                    title: __('Scheduled task run'),
                    productTitle: __(':environment scheduled task', ['environment' => $mapping['label']]),
                    status: (string) $taskRun->status,
                    recordedAt: $taskRun->created_at,
                    attemptedAt: $taskRun->started_at,
                    completedAt: $taskRun->finished_at,
                    resultUrl: Route::has('automation.index')
                        ? route('automation.index', ['organization_id' => $mapping['organization_id']])
                        : null,
                    mapping: $mapping,
                    workspace: $workspace,
                    detailRoute: __('automation history'),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappings
     * @return Collection<int, ProjectWorkflowRun>
     */
    private function databaseClones(array $mappings, Workspace $workspace, int $limit): Collection
    {
        if (! Schema::connection('deployer')->hasTable('database_clones')
            || ! Schema::connection('deployer')->hasTable('environment_resources')) {
            return collect();
        }

        return DatabaseClone::query()
            ->whereHas('source', fn ($query) => $query->whereIn('environment_id', array_keys($mappings)))
            ->whereHas('target', fn ($query) => $query->whereIn('environment_id', array_keys($mappings)))
            ->with(['source:id,environment_id', 'target:id,environment_id'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'source_resource_id', 'target_resource_id', 'status', 'started_at', 'finished_at', 'created_at', 'updated_at'])
            ->map(function (DatabaseClone $clone) use ($mappings, $workspace): ?ProjectWorkflowRun {
                $sourceMapping = $mappings[(string) $clone->source?->environment_id] ?? null;
                $targetMapping = $mappings[(string) $clone->target?->environment_id] ?? null;

                if ($sourceMapping === null || $targetMapping === null
                    || $sourceMapping['organization_id'] !== $targetMapping['organization_id']) {
                    return null;
                }

                return $this->run(
                    key: 'deployer:database-clone:'.$clone->getKey(),
                    title: __('Database clone'),
                    productTitle: __('Database operation · :environment', ['environment' => $sourceMapping['label']]),
                    status: (string) $clone->status,
                    recordedAt: $clone->created_at,
                    attemptedAt: $clone->started_at,
                    completedAt: $clone->finished_at,
                    resultUrl: Route::has('databases.index')
                        ? route('databases.index', ['organization_id' => $sourceMapping['organization_id']])
                        : null,
                    mapping: $sourceMapping,
                    workspace: $workspace,
                    detailRoute: __('database operations'),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappings
     * @return Collection<int, ProjectWorkflowRun>
     */
    private function databaseOperations(array $mappings, Workspace $workspace, int $limit): Collection
    {
        if (! Schema::connection('deployer')->hasTable('database_operation_runs')
            || ! Schema::connection('deployer')->hasTable('environment_resources')) {
            return collect();
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays(30);

        return DatabaseOperationRun::query()
            ->whereIn('operation', ['inspection', 'user_apply', 'user_remove'])
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('status', [DatabaseOperationRun::QUEUED, DatabaseOperationRun::RUNNING])
                    ->orWhere(function ($terminal) use ($cutoff): void {
                        $terminal->whereNotIn('status', [DatabaseOperationRun::QUEUED, DatabaseOperationRun::RUNNING])
                            ->where('created_at', '>=', $cutoff);
                    });
            })
            ->whereHas('resource', fn ($query) => $query
                ->whereIn('environment_id', array_keys($mappings))
                ->whereIn('type', ['mysql', 'postgresql']))
            ->with('resource:id,environment_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'environment_resource_id',
                'operation',
                'status',
                'created_at',
                'started_at',
                'finished_at',
                'lease_expires_at',
            ])
            ->map(function (DatabaseOperationRun $operation) use ($mappings, $workspace): ?ProjectWorkflowRun {
                $mapping = $mappings[(string) $operation->resource?->environment_id] ?? null;

                if ($mapping === null) {
                    return null;
                }

                [$title, $productTitle] = match ($operation->operation) {
                    'inspection' => [__('Database inspection'), __(':environment database inspection', ['environment' => $mapping['label']])],
                    'user_apply' => [__('Database credential setup'), __(':environment database credential setup', ['environment' => $mapping['label']])],
                    'user_remove' => [__('Database credential removal'), __(':environment database credential removal', ['environment' => $mapping['label']])],
                };
                $status = (string) $operation->status;

                if (($status === DatabaseOperationRun::RUNNING && $operation->lease_expires_at?->isPast())
                    || ($status === DatabaseOperationRun::QUEUED && $operation->created_at?->lt(now()->subMinutes(10)))) {
                    $status = 'unknown';
                }

                return $this->run(
                    key: 'deployer:database-operation:'.$operation->getKey(),
                    title: $title,
                    productTitle: $productTitle,
                    status: $status,
                    recordedAt: $operation->created_at,
                    attemptedAt: $operation->started_at,
                    completedAt: $operation->finished_at,
                    resultUrl: Route::has('databases.index')
                        ? route('databases.index', ['organization_id' => $mapping['organization_id']]).'#database-insights'
                        : null,
                    mapping: $mapping,
                    workspace: $workspace,
                    detailRoute: __('database operations'),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappings
     * @return Collection<int, ProjectWorkflowRun>
     */
    private function serverCommands(array $mappings, Workspace $workspace, int $limit): Collection
    {
        if (! Schema::connection('deployer')->hasTable('server_command_executions')) {
            return collect();
        }

        $serverMappings = collect($mappings)
            ->filter(fn (array $mapping): bool => $mapping['environment']->server_id !== null)
            ->keyBy(fn (array $mapping): string => (string) $mapping['environment']->server_id);

        if ($serverMappings->isEmpty()) {
            return collect();
        }

        $servers = Server::query()
            ->whereIn('id', $serverMappings->keys())
            ->get(['id', 'organization_id'])
            ->filter(fn (Server $server): bool => isset($serverMappings[(string) $server->getKey()])
                && (int) $server->organization_id === $serverMappings[(string) $server->getKey()]['organization_id'])
            ->keyBy(fn (Server $server): string => (string) $server->getKey());

        if ($servers->isEmpty()) {
            return collect();
        }

        return ServerCommandExecution::query()
            ->whereIn('server_id', $servers->keys())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'server_id', 'status', 'started_at', 'finished_at', 'created_at', 'updated_at'])
            ->map(function (ServerCommandExecution $execution) use ($serverMappings, $servers, $workspace): ?ProjectWorkflowRun {
                $serverId = (string) $execution->server_id;
                $mapping = $servers->has($serverId) ? $serverMappings->get($serverId) : null;

                return $mapping === null ? null : $this->run(
                    key: 'deployer:server-command:'.$execution->getKey(),
                    title: __('Server command'),
                    productTitle: __(':environment server operation', ['environment' => $mapping['label']]),
                    status: (string) $execution->status,
                    recordedAt: $execution->created_at,
                    attemptedAt: $execution->started_at,
                    completedAt: $execution->finished_at,
                    resultUrl: Route::has('servers.commands.index')
                        ? route('servers.commands.index', [
                            'server' => $serverId,
                            'organization_id' => $mapping['organization_id'],
                        ])
                        : null,
                    mapping: $mapping,
                    workspace: $workspace,
                    detailRoute: __('server command history'),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array{project: CoreProject, environment: Environment, label: string, organization_id: int}  $mapping
     */
    private function run(
        string $key,
        string $title,
        string $productTitle,
        string $status,
        mixed $recordedAt,
        mixed $attemptedAt,
        mixed $completedAt,
        ?string $resultUrl,
        array $mapping,
        Workspace $workspace,
        string $detailRoute,
    ): ProjectWorkflowRun {
        $state = match ($status) {
            'queued' => ProjectWorkflowStepState::Pending,
            'running', 'delivering' => ProjectWorkflowStepState::Processing,
            'awaiting_approval' => ProjectWorkflowStepState::AwaitingApproval,
            'delivered' => ProjectWorkflowStepState::Delivered,
            'blocked' => ProjectWorkflowStepState::Blocked,
            'succeeded' => ProjectWorkflowStepState::Succeeded,
            'failed', 'delivery_failed' => ProjectWorkflowStepState::Failed,
            'canceled', 'cancelled' => ProjectWorkflowStepState::Discarded,
            default => ProjectWorkflowStepState::Unknown,
        };
        $timestamp = $recordedAt?->toImmutable()->utc()
            ?? $attemptedAt?->toImmutable()->utc()
            ?? $completedAt?->toImmutable()->utc()
            ?? CarbonImmutable::now('UTC');

        return new ProjectWorkflowRun(
            key: $key,
            title: $title,
            recordedAt: $timestamp,
            projectId: (string) $mapping['project']->getKey(),
            projectName: $mapping['project']->name,
            projectUrl: route('core.projects.show', [$workspace, $mapping['project']]),
            steps: [new ProjectWorkflowStep(
                product: 'deployer',
                productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                title: $productTitle,
                detail: $this->detail($state, $detailRoute),
                state: $state,
                recordedAt: $timestamp,
                attemptedAt: $attemptedAt?->toImmutable()->utc(),
                completedAt: $completedAt?->toImmutable()->utc(),
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'] ?? null,
                environmentName: $mapping['canonical_environment_name'] ?? $mapping['label'],
            )],
        );
    }

    private function detail(ProjectWorkflowStepState $state, string $detailRoute): string
    {
        return match ($state) {
            ProjectWorkflowStepState::Pending => __('This Deployer task is queued.'),
            ProjectWorkflowStepState::AwaitingApproval => __('This Deployer task is waiting for approval.'),
            ProjectWorkflowStepState::Processing => __('This Deployer task is running.'),
            ProjectWorkflowStepState::Delivered => __('This Deployer task was delivered to its queue.'),
            ProjectWorkflowStepState::Blocked => __('This Deployer task is blocked. Open :destination for authorized details.', ['destination' => $detailRoute]),
            ProjectWorkflowStepState::Succeeded => __('This Deployer task completed successfully.'),
            ProjectWorkflowStepState::Failed => __('This Deployer task failed. Open :destination for authorized details.', ['destination' => $detailRoute]),
            ProjectWorkflowStepState::Discarded => __('This Deployer task was canceled.'),
            default => __('The Deployer task state is unavailable. Open :destination for current information.', ['destination' => $detailRoute]),
        };
    }
}
