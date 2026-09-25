<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\WorkspaceActivityProvider;
use App\Core\Contracts\WorkspaceWebhookDeliveryProvider;
use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Data\Projects\WorkspaceActivitySnapshot;
use App\Core\Data\Projects\WorkspaceWebhookDelivery;
use App\Core\Data\Projects\WorkspaceWebhookDeliverySnapshot;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Monitor\Data\Telemetry\AlertDeliveryStatus;
use App\Modules\Monitor\Data\Telemetry\AlertDestinationType;
use App\Modules\Monitor\Data\Telemetry\IngestStatus;
use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\HeartbeatRun;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\IngestReceipt;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\MonitorCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Read authorized telemetry processing state for the Core project activity feed. */
final class MonitorWorkspaceActivityProvider implements WorkspaceActivityProvider, WorkspaceWebhookDeliveryProvider
{
    public function __construct(
        private readonly MonitorProjectLink $projectLinks,
        private readonly WorkspaceProjectAccess $projectAccess,
        private readonly ProductWorkspaceAccess $workspaceAccess,
        private readonly LegacyIdentityResolver $identities,
    ) {}

    /**
     * @param  Collection<int, CoreProject>  $projects
     */
    public function recentWebhookDeliveriesForWorkspace(
        PlatformUser $user,
        CoreWorkspace $workspace,
        Collection $projects,
        int $limit,
    ): WorkspaceWebhookDeliverySnapshot {
        if ($projects->isEmpty()) {
            return new WorkspaceWebhookDeliverySnapshot(collect());
        }

        try {
            $mappedEnvironments = $this->mappedEnvironments($user, $workspace, $projects);
            if ($mappedEnvironments === []
                || ! Schema::connection('monitor')->hasTable('alert_deliveries')
                || ! Schema::connection('monitor')->hasTable('alert_destinations')
                || ! Schema::connection('monitor')->hasTable('incidents')
                || ! Schema::connection('monitor')->hasTable('alert_rules')) {
                return new WorkspaceWebhookDeliverySnapshot(collect());
            }

            $mappedMonitors = $this->mappedMonitors($mappedEnvironments);
            $environmentIds = array_keys($mappedEnvironments);
            $workspaceIds = collect($mappedEnvironments)
                ->map(fn (array $mapping): string => (string) $mapping['workspace_id'])
                ->unique()
                ->values()
                ->all();
            $managerWorkspaceIds = $this->managerWorkspaceIds($user, $workspaceIds);
            $cutoff = CarbonImmutable::now('UTC')->subDays(30);
            $mappedRuleIds = AlertRule::withTrashed()
                ->whereIn('environment_id', $environmentIds)
                ->select('id');
            $activeStatuses = [
                AlertDeliveryStatus::Queued->value,
                AlertDeliveryStatus::Sending->value,
                AlertDeliveryStatus::Retrying->value,
                AlertDeliveryStatus::Failed->value,
                AlertDeliveryStatus::Uncertain->value,
            ];

            $deliveries = AlertDelivery::query()
                ->whereIn('workspace_id', $workspaceIds)
                ->where(fn ($query) => $query
                    ->whereIn('status', $activeStatuses)
                    ->orWhere(fn ($terminal) => $terminal
                        ->whereIn('status', [AlertDeliveryStatus::Accepted->value, AlertDeliveryStatus::Cancelled->value])
                        ->where('updated_at', '>=', $cutoff)))
                ->whereHas('destination', fn ($query) => $query
                    ->where('type', AlertDestinationType::Webhook->value)
                    ->whereColumn('alert_destinations.workspace_id', 'alert_deliveries.workspace_id'))
                ->whereHas('incident', fn ($query) => $query->where(fn ($incident) => $incident
                    ->whereIn('monitor_id', array_keys($mappedMonitors))
                    ->orWhereIn('alert_rule_id', $mappedRuleIds)))
                ->with([
                    'incident:id,monitor_id,alert_rule_id',
                    'incident.monitor:id,environment_id',
                    'incident.alertRule:id,environment_id',
                    'destination:id,workspace_id,type',
                ])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(max(1, min(100, $limit)))
                ->get([
                    'id',
                    'workspace_id',
                    'alert_destination_id',
                    'incident_id',
                    'status',
                    'attempt_count',
                    'created_at',
                    'updated_at',
                ]);

            $rows = $deliveries
                ->map(function (AlertDelivery $delivery) use ($mappedEnvironments, $mappedMonitors, $managerWorkspaceIds): ?WorkspaceWebhookDelivery {
                    $incident = $delivery->incident;
                    if ($incident === null
                        || $delivery->destination?->type !== AlertDestinationType::Webhook) {
                        return null;
                    }

                    $mapping = $incident->monitor_id !== null
                        ? ($mappedMonitors[(string) $incident->monitor?->environment_id] ?? null)
                        : ($mappedEnvironments[(string) $incident->alertRule?->environment_id] ?? null);

                    if ($mapping === null
                        || (string) $delivery->workspace_id !== (string) $mapping['workspace_id']
                        || (string) $delivery->destination?->workspace_id !== (string) $delivery->workspace_id) {
                        return null;
                    }

                    $status = $delivery->status;
                    $canManage = in_array((string) $mapping['workspace_id'], $managerWorkspaceIds, true);
                    $detailUrl = $canManage && Route::has('monitor.alert-deliveries.show')
                        ? route('monitor.alert-deliveries.show', [
                            'alertDelivery' => $delivery->getKey(),
                            'workspace_id' => $mapping['workspace_id'],
                        ])
                        : (Route::has('monitor.incidents.show') ? route('monitor.incidents.show', [
                            'incident' => $incident->getKey(),
                            'workspace_id' => $mapping['workspace_id'],
                        ]) : null);

                    return new WorkspaceWebhookDelivery(
                        key: 'monitor:webhook-delivery:'.$delivery->getKey(),
                        product: 'monitor',
                        productLabel: (string) config('platform.products.monitor.label', __('Monitor')),
                        projectName: $mapping['project']->name,
                        title: __('Signed alert webhook'),
                        status: $status->value,
                        statusLabel: $status->label(),
                        attemptCount: (int) $delivery->attempt_count,
                        recordedAt: $delivery->created_at?->toImmutable()->utc()
                            ?? $delivery->updated_at?->toImmutable()->utc()
                            ?? CarbonImmutable::now('UTC'),
                        resultUrl: $detailUrl,
                    );
                })
                ->filter()
                ->values();

            return new WorkspaceWebhookDeliverySnapshot($rows);
        } catch (LostConnectionException|QueryException) {
            return new WorkspaceWebhookDeliverySnapshot(collect(), available: false);
        }
    }

    /**
     * @param  Collection<int, CoreProject>  $projects
     */
    public function recentForWorkspace(
        PlatformUser $user,
        CoreWorkspace $workspace,
        Collection $projects,
        int $limit,
    ): WorkspaceActivitySnapshot {
        if ($projects->isEmpty()) {
            return new WorkspaceActivitySnapshot(collect());
        }

        try {
            $mappedEnvironments = $this->mappedEnvironments($user, $workspace, $projects);

            if ($mappedEnvironments === []) {
                return new WorkspaceActivitySnapshot(collect());
            }

            $resultLimit = max(1, min(100, $limit));
            $recentCutoff = CarbonImmutable::now('UTC')->subDays(30);
            $receipts = IngestReceipt::query()
                ->whereIn('environment_id', array_keys($mappedEnvironments))
                ->where(fn ($query) => $query
                    ->whereIn('status', [IngestStatus::Queued->value, IngestStatus::Processing->value, IngestStatus::Retrying->value, IngestStatus::Failed->value])
                    ->orWhere(fn ($completed) => $completed
                        ->where('status', IngestStatus::Completed->value)
                        ->where('processed_at', '>=', $recentCutoff)))
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->limit($resultLimit)
                ->get([
                    'id',
                    'workspace_id',
                    'environment_id',
                    'status',
                    'event_count',
                    'accepted_count',
                    'received_at',
                    'last_received_at',
                    'processing_started_at',
                    'processed_at',
                    'failed_at',
                    'created_at',
                    'updated_at',
                ]);

            $runs = $receipts
                ->map(fn (IngestReceipt $receipt): ?ProjectWorkflowRun => $this->runFor($receipt, $workspace, $mappedEnvironments))
                ->filter();

            $mappedMonitors = $this->mappedMonitors($mappedEnvironments);
            if ($mappedMonitors !== []) {
                $monitorIds = array_keys($mappedMonitors);
                $checks = MonitorCheck::query()
                    ->whereIn('monitor_id', $monitorIds)
                    ->where(fn ($query) => $query
                        ->whereIn('status', ['queued', 'running'])
                        ->orWhere(fn ($terminal) => $terminal
                            ->where('finished_at', '>=', $recentCutoff)
                            ->whereNotIn('status', ['queued', 'running'])))
                    ->orderByDesc('scheduled_at')
                    ->orderByDesc('id')
                    ->limit($resultLimit)
                    ->get(['id', 'monitor_id', 'status', 'outcome', 'scheduled_at', 'started_at', 'finished_at', 'created_at', 'updated_at']);

                $heartbeatRuns = HeartbeatRun::query()
                    ->whereIn('monitor_id', collect($mappedMonitors)
                        ->filter(fn (array $mapping): bool => $mapping['monitor']->type === 'heartbeat')
                        ->keys()
                        ->all())
                    ->where(fn ($query) => $query
                        ->where('status', 'running')
                        ->orWhere(fn ($terminal) => $terminal
                            ->where('updated_at', '>=', $recentCutoff)
                            ->where('status', '!=', 'running')))
                    ->orderByDesc('started_at')
                    ->orderByDesc('id')
                    ->limit($resultLimit)
                    ->get(['id', 'monitor_id', 'status', 'started_at', 'finished_at', 'deadline_at', 'created_at', 'updated_at']);

                $runs = $runs
                    ->merge($checks->map(fn (MonitorCheck $check): ?ProjectWorkflowRun => $this->runForCheck($check, $workspace, $mappedMonitors)))
                    ->merge($heartbeatRuns->map(fn (HeartbeatRun $run): ?ProjectWorkflowRun => $this->runForHeartbeat($run, $workspace, $mappedMonitors)));
            }

            $runs = $runs->merge($this->incidents($workspace, $mappedEnvironments, $mappedMonitors, $resultLimit));
            $runs = $runs->merge($this->alertDeliveries($user, $workspace, $mappedEnvironments, $mappedMonitors, $resultLimit));

            return new WorkspaceActivitySnapshot($runs
                ->filter()
                ->sortByDesc(fn (ProjectWorkflowRun $run): int => $run->recordedAt->getTimestamp())
                ->take($resultLimit)
                ->values());
        } catch (LostConnectionException|QueryException) {
            return new WorkspaceActivitySnapshot(collect(), available: false);
        }
    }

    /**
     * @param  Collection<int, CoreProject>  $projects
     * @return array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int}>
     */
    private function mappedEnvironments(PlatformUser $user, CoreWorkspace $workspace, Collection $projects): array
    {
        $mapped = [];

        foreach ($projects as $project) {
            if (! $this->projectAccess->canAccessProductResource($user, $project, 'monitor')) {
                continue;
            }

            $resources = ProjectResource::query()
                ->where('project_id', $project->getKey())
                ->where('product', 'monitor')
                ->where('resource_type', 'environment')
                ->where('status', 'active')
                ->orderBy('id')
                ->limit(100)
                ->with('environment:id,name')
                ->get(['id', 'project_id', 'product', 'resource_type', 'resource_id', 'status', 'name', 'environment_id']);

            foreach ($resources as $resource) {
                $environment = $this->projectLinks->accessibleEnvironment($user, $resource);
                $monitorWorkspace = $environment?->application?->workspace;

                if ($environment === null || $monitorWorkspace === null) {
                    continue;
                }

                $mappedWorkspaceId = $this->identities->canonicalIdForSource(
                    'monitor',
                    'workspace',
                    (string) $monitorWorkspace->getKey(),
                    'workspace',
                );

                if ($mappedWorkspaceId !== (string) $workspace->getKey()
                    || ! $this->workspaceAccess->allows($user, 'monitor', 'workspace', (string) $monitorWorkspace->getKey())) {
                    continue;
                }

                $mapped[(string) $environment->getKey()] = [
                    'project' => $project,
                    'environment' => $environment,
                    'label' => filled($resource->name) ? $resource->name : $environment->name,
                    'canonical_environment_id' => $resource->environment_id === null ? null : (string) $resource->environment_id,
                    'canonical_environment_name' => $resource->environment?->name,
                    'workspace_id' => (int) $monitorWorkspace->getKey(),
                ];
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int}>  $mappedEnvironments
     * @return array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int, monitor: Monitor}>
     */
    private function mappedMonitors(array $mappedEnvironments): array
    {
        if ($mappedEnvironments === []) {
            return [];
        }

        $monitors = Monitor::query()
            ->whereIn('environment_id', array_keys($mappedEnvironments))
            ->orderBy('id')
            ->get(['id', 'environment_id', 'name', 'type']);
        $mapped = [];

        foreach ($monitors as $monitor) {
            $environmentMapping = $mappedEnvironments[(string) $monitor->environment_id] ?? null;
            if ($environmentMapping === null) {
                continue;
            }

            $mapped[(string) $monitor->getKey()] = [
                ...$environmentMapping,
                'monitor' => $monitor,
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int}>  $mappedEnvironments
     */
    private function runFor(IngestReceipt $receipt, CoreWorkspace $workspace, array $mappedEnvironments): ?ProjectWorkflowRun
    {
        $mapping = $mappedEnvironments[(string) $receipt->environment_id] ?? null;
        $environment = $mapping['environment'] ?? null;

        if ($mapping === null
            || $environment === null
            || (int) $receipt->workspace_id !== $mapping['workspace_id']
            || (int) $environment->application?->workspace_id !== $mapping['workspace_id']) {
            return null;
        }

        $project = $mapping['project'];
        $status = IngestStatus::tryFrom((string) $receipt->getRawOriginal('status'));
        $state = match ($status) {
            IngestStatus::Queued => ProjectWorkflowStepState::Pending,
            IngestStatus::Processing, IngestStatus::Retrying => ProjectWorkflowStepState::Processing,
            IngestStatus::Completed => ProjectWorkflowStepState::Succeeded,
            IngestStatus::Failed => ProjectWorkflowStepState::Failed,
            null => ProjectWorkflowStepState::Unknown,
        };
        $recordedAt = $receipt->received_at?->toImmutable()->utc()
            ?? $receipt->created_at?->toImmutable()->utc()
            ?? CarbonImmutable::now('UTC');
        $attemptedAt = $receipt->processing_started_at?->toImmutable()->utc()
            ?? ($state === ProjectWorkflowStepState::Failed ? $receipt->failed_at?->toImmutable()->utc() : null);
        $completedAt = $state === ProjectWorkflowStepState::Succeeded
            ? $receipt->processed_at?->toImmutable()->utc()
            : null;
        $resultUrl = Route::has('monitor.environments.show')
            ? route('monitor.environments.show', [
                'application' => $environment->application_id,
                'environment' => $environment->getKey(),
                'workspace_id' => $mapping['workspace_id'],
            ])
            : null;

        return new ProjectWorkflowRun(
            key: 'monitor:telemetry-receipt:'.$receipt->getKey(),
            title: __('Telemetry processing'),
            recordedAt: $recordedAt,
            projectId: (string) $project->getKey(),
            projectName: $project->name,
            projectUrl: route('core.projects.show', [$workspace, $project]),
            steps: [new ProjectWorkflowStep(
                product: 'monitor',
                productLabel: (string) config('platform.products.monitor.label', __('Monitor')),
                title: __(':environment telemetry', ['environment' => $mapping['label']]),
                detail: $this->detail($state, (int) $receipt->accepted_count, (int) $receipt->event_count),
                state: $state,
                recordedAt: $recordedAt,
                attemptedAt: $attemptedAt,
                completedAt: $completedAt,
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'],
                environmentName: $mapping['canonical_environment_name'] ?: $mapping['label'],
            )],
        );
    }

    private function detail(ProjectWorkflowStepState $state, int $accepted, int $total): string
    {
        return match ($state) {
            ProjectWorkflowStepState::Pending => __('Telemetry is queued for processing. :accepted of :total events are accepted.', ['accepted' => $accepted, 'total' => $total]),
            ProjectWorkflowStepState::Processing => __('Telemetry is being processed. :accepted of :total events are accepted.', ['accepted' => $accepted, 'total' => $total]),
            ProjectWorkflowStepState::Succeeded => __('Telemetry processing completed. :accepted of :total events were accepted.', ['accepted' => $accepted, 'total' => $total]),
            ProjectWorkflowStepState::Failed => __('Telemetry processing failed. Open Monitor for authorized details.'),
            default => __('The telemetry processing state is unavailable. Open Monitor to review its current status.'),
        };
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int, monitor: Monitor}>  $mappedMonitors
     */
    private function runForCheck(MonitorCheck $check, CoreWorkspace $workspace, array $mappedMonitors): ?ProjectWorkflowRun
    {
        $mapping = $mappedMonitors[(string) $check->monitor_id] ?? null;
        if ($mapping === null) {
            return null;
        }

        $state = match ($check->status) {
            'queued' => ProjectWorkflowStepState::Pending,
            'running' => ProjectWorkflowStepState::Processing,
            'completed' => match ($check->outcome) {
                'up' => ProjectWorkflowStepState::Succeeded,
                'down' => ProjectWorkflowStepState::Failed,
                default => ProjectWorkflowStepState::Unknown,
            },
            default => ProjectWorkflowStepState::Unknown,
        };
        if ($state === ProjectWorkflowStepState::Succeeded) {
            return null;
        }

        $monitor = $mapping['monitor'];
        $project = $mapping['project'];
        $recordedAt = $check->scheduled_at?->toImmutable()->utc()
            ?? $check->created_at?->toImmutable()->utc()
            ?? CarbonImmutable::now('UTC');
        $resultUrl = Route::has('monitor.monitors.show')
            ? route('monitor.monitors.show', ['monitor' => $monitor->getKey(), 'workspace_id' => $mapping['workspace_id']])
            : null;

        return new ProjectWorkflowRun(
            key: 'monitor:check:'.$check->getKey(),
            title: __('Monitor check: :name', ['name' => $monitor->name]),
            recordedAt: $recordedAt,
            projectId: (string) $project->getKey(),
            projectName: $project->name,
            projectUrl: route('core.projects.show', [$workspace, $project]),
            steps: [new ProjectWorkflowStep(
                product: 'monitor',
                productLabel: (string) config('platform.products.monitor.label', __('Monitor')),
                title: __('Scheduled health check · :environment', ['environment' => $mapping['label']]),
                detail: match ($state) {
                    ProjectWorkflowStepState::Pending => __('The scheduled check is queued.'),
                    ProjectWorkflowStepState::Processing => __('The scheduled check is running.'),
                    ProjectWorkflowStepState::Failed => __('The check reported an unhealthy result. Open Monitor for authorized evidence.'),
                    default => __('The check result is unavailable. Open Monitor to review its current status.'),
                },
                state: $state,
                recordedAt: $recordedAt,
                attemptedAt: $check->started_at?->toImmutable()->utc(),
                completedAt: $check->finished_at?->toImmutable()->utc(),
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'],
                environmentName: $mapping['canonical_environment_name'] ?: $mapping['label'],
            )],
        );
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int, monitor: Monitor}>  $mappedMonitors
     */
    private function runForHeartbeat(HeartbeatRun $run, CoreWorkspace $workspace, array $mappedMonitors): ?ProjectWorkflowRun
    {
        $mapping = $mappedMonitors[(string) $run->monitor_id] ?? null;
        if ($mapping === null) {
            return null;
        }

        $state = match ($run->status) {
            'running' => ProjectWorkflowStepState::Processing,
            'success' => ProjectWorkflowStepState::Succeeded,
            'failed', 'timed_out' => ProjectWorkflowStepState::Failed,
            default => ProjectWorkflowStepState::Unknown,
        };
        if ($state === ProjectWorkflowStepState::Succeeded) {
            return null;
        }

        $monitor = $mapping['monitor'];
        $project = $mapping['project'];
        $recordedAt = ($run->status === 'running' ? $run->started_at : ($run->finished_at ?? $run->updated_at))?->toImmutable()->utc()
            ?? $run->started_at?->toImmutable()->utc()
            ?? $run->created_at?->toImmutable()->utc()
            ?? CarbonImmutable::now('UTC');
        $resultUrl = Route::has('monitor.monitors.show')
            ? route('monitor.monitors.show', ['monitor' => $monitor->getKey(), 'workspace_id' => $mapping['workspace_id']])
            : null;

        return new ProjectWorkflowRun(
            key: 'monitor:heartbeat-run:'.$run->getKey(),
            title: __('Heartbeat: :name', ['name' => $monitor->name]),
            recordedAt: $recordedAt,
            projectId: (string) $project->getKey(),
            projectName: $project->name,
            projectUrl: route('core.projects.show', [$workspace, $project]),
            steps: [new ProjectWorkflowStep(
                product: 'monitor',
                productLabel: (string) config('platform.products.monitor.label', __('Monitor')),
                title: __('Heartbeat signal'),
                detail: match ($state) {
                    ProjectWorkflowStepState::Processing => __('This heartbeat run is still active.'),
                    ProjectWorkflowStepState::Failed => __('The heartbeat run failed or missed its deadline. Open Monitor for authorized evidence.'),
                    default => __('The heartbeat run state is unavailable. Open Monitor to review its current status.'),
                },
                state: $state,
                recordedAt: $recordedAt,
                attemptedAt: $run->started_at?->toImmutable()->utc(),
                completedAt: $run->finished_at?->toImmutable()->utc(),
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'],
                environmentName: $mapping['canonical_environment_name'] ?: $mapping['label'],
            )],
        );
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int}>  $mappedEnvironments
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int, monitor: Monitor}>  $mappedMonitors
     * @return Collection<int, ProjectWorkflowRun>
     */
    private function alertDeliveries(
        PlatformUser $user,
        CoreWorkspace $workspace,
        array $mappedEnvironments,
        array $mappedMonitors,
        int $limit,
    ): Collection {
        if (! Schema::connection('monitor')->hasTable('alert_deliveries')
            || ! Schema::connection('monitor')->hasTable('incidents')
            || ! Schema::connection('monitor')->hasTable('alert_rules')
            || ! Schema::connection('monitor')->hasTable('alert_destinations')) {
            return collect();
        }

        $environmentIds = array_keys($mappedEnvironments);
        $workspaceIds = collect($mappedEnvironments)
            ->map(fn (array $mapping): string => (string) $mapping['workspace_id'])
            ->unique()
            ->values()
            ->all();
        $managerWorkspaceIds = $this->managerWorkspaceIds($user, $workspaceIds);
        $cutoff = CarbonImmutable::now('UTC')->subDays(30);
        $mappedRuleIds = AlertRule::withTrashed()
            ->whereIn('environment_id', $environmentIds)
            ->select('id');
        $recentOrActionable = [
            AlertDeliveryStatus::Queued->value,
            AlertDeliveryStatus::Sending->value,
            AlertDeliveryStatus::Retrying->value,
            AlertDeliveryStatus::Failed->value,
            AlertDeliveryStatus::Uncertain->value,
        ];

        return AlertDelivery::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where(fn ($query) => $query
                ->whereIn('status', $recentOrActionable)
                ->orWhere(fn ($terminal) => $terminal
                    ->whereIn('status', [AlertDeliveryStatus::Accepted->value, AlertDeliveryStatus::Cancelled->value])
                    ->where('updated_at', '>=', $cutoff)))
            ->whereHas('incident', fn ($query) => $query->where(fn ($incident) => $incident
                ->whereIn('monitor_id', array_keys($mappedMonitors))
                ->orWhereIn('alert_rule_id', $mappedRuleIds)))
            ->with([
                'incident:id,monitor_id,alert_rule_id',
                'incident.monitor:id,environment_id',
                'incident.alertRule:id,environment_id',
                'destination:id,workspace_id',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'workspace_id',
                'alert_destination_id',
                'incident_id',
                'status',
                'attempt_count',
                'accepted_at',
                'failed_at',
                'created_at',
                'updated_at',
            ])
            ->map(function (AlertDelivery $delivery) use ($workspace, $mappedEnvironments, $mappedMonitors, $managerWorkspaceIds): ?ProjectWorkflowRun {
                $incident = $delivery->incident;
                if ($incident === null) {
                    return null;
                }

                $mapping = $incident->monitor_id !== null
                    ? ($mappedMonitors[(string) $incident->monitor?->environment_id] ?? null)
                    : ($mappedEnvironments[(string) $incident->alertRule?->environment_id] ?? null);

                if ($mapping === null
                    || (string) $delivery->workspace_id !== (string) $mapping['workspace_id']
                    || (string) $delivery->destination?->workspace_id !== (string) $delivery->workspace_id) {
                    return null;
                }

                return $this->runForAlertDelivery(
                    $delivery,
                    $incident,
                    $mapping,
                    $workspace,
                    in_array((string) $mapping['workspace_id'], $managerWorkspaceIds, true),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int, canonical_environment_id: ?string, canonical_environment_name: ?string}>  $mappedEnvironments
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int, canonical_environment_id: ?string, canonical_environment_name: ?string, monitor: Monitor}>  $mappedMonitors
     * @return Collection<int, ProjectWorkflowRun>
     */
    private function incidents(
        CoreWorkspace $workspace,
        array $mappedEnvironments,
        array $mappedMonitors,
        int $limit,
    ): Collection {
        if (! Schema::connection('monitor')->hasTable('incidents')) {
            return collect();
        }

        $environmentIds = array_keys($mappedEnvironments);
        $monitorIds = array_keys($mappedMonitors);
        $ruleIds = AlertRule::withTrashed()
            ->whereIn('environment_id', $environmentIds)
            ->select('id');
        $recentCutoff = CarbonImmutable::now('UTC')->subDays(30);

        return Incident::query()
            ->where(fn ($query) => $query
                ->whereIn('alert_rule_id', $ruleIds)
                ->orWhereIn('monitor_id', $monitorIds))
            ->where(fn ($query) => $query
                ->whereIn('status', ['open', 'acknowledged'])
                ->orWhere(fn ($resolved) => $resolved
                    ->where('status', 'resolved')
                    ->where('resolved_at', '>=', $recentCutoff)))
            ->with(['monitor:id,environment_id', 'alertRule:id,environment_id'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'alert_rule_id',
                'monitor_id',
                'status',
                'opened_at',
                'acknowledged_at',
                'resolved_at',
                'closure_reason',
                'updated_at',
            ])
            ->map(fn (Incident $incident): ?ProjectWorkflowRun => $this->runForIncident(
                $incident,
                $workspace,
                $mappedEnvironments,
                $mappedMonitors,
            ))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int, canonical_environment_id: ?string, canonical_environment_name: ?string}>  $mappedEnvironments
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int, canonical_environment_id: ?string, canonical_environment_name: ?string, monitor?: Monitor}>  $mappedMonitors
     */
    private function runForIncident(
        Incident $incident,
        CoreWorkspace $workspace,
        array $mappedEnvironments,
        array $mappedMonitors,
    ): ?ProjectWorkflowRun {
        $environmentId = $incident->monitor_id !== null
            ? (string) $incident->monitor?->environment_id
            : (string) $incident->alertRule?->environment_id;
        $mapping = $incident->monitor_id !== null
            ? ($mappedMonitors[(string) $incident->monitor_id] ?? null)
            : ($mappedEnvironments[$environmentId] ?? null);

        if ($mapping === null || $environmentId === '') {
            return null;
        }

        $state = match ($incident->status) {
            'open' => ProjectWorkflowStepState::Failed,
            'acknowledged' => ProjectWorkflowStepState::Blocked,
            'resolved' => ProjectWorkflowStepState::Succeeded,
            default => null,
        };

        if ($state === null) {
            return null;
        }

        $occurredAt = (match ($incident->status) {
            'open' => $incident->opened_at,
            'acknowledged' => $incident->acknowledged_at ?? $incident->updated_at,
            'resolved' => $incident->resolved_at ?? $incident->updated_at,
        })?->toImmutable()->utc() ?? CarbonImmutable::now('UTC');
        $project = $mapping['project'];
        $resultUrl = Route::has('monitor.incidents.show')
            ? route('monitor.incidents.show', [
                'incident' => $incident->getKey(),
                'workspace_id' => $mapping['workspace_id'],
            ])
            : null;
        $label = $mapping['canonical_environment_name'] ?: $mapping['label'];

        return new ProjectWorkflowRun(
            key: 'monitor:incident:'.$incident->getKey().':'.$incident->status.':'.$occurredAt->format('U.u'),
            title: __('Monitor incident'),
            recordedAt: $occurredAt,
            projectId: (string) $project->getKey(),
            projectName: $project->name,
            projectUrl: route('core.projects.show', [$workspace, $project]),
            steps: [new ProjectWorkflowStep(
                product: 'monitor',
                productLabel: (string) config('platform.products.monitor.label', __('Monitor')),
                title: __('Incident · :environment', ['environment' => $label]),
                detail: match ($incident->status) {
                    'open' => __('A Monitor incident is open. Open Monitor for authorized details.'),
                    'acknowledged' => __('A Monitor incident has been acknowledged and remains active. Open Monitor for authorized details.'),
                    'resolved' => $incident->closure_reason === 'recovered'
                        ? __('Monitor recorded recovery for this incident.')
                        : __('This Monitor incident was resolved.'),
                },
                state: $state,
                recordedAt: $occurredAt,
                completedAt: $incident->status === 'resolved' ? $occurredAt : null,
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'],
                environmentName: $label,
            )],
        );
    }

    /** @param  list<string>  $workspaceIds
     * @return list<string>
     */
    private function managerWorkspaceIds(PlatformUser $user, array $workspaceIds): array
    {
        if ($workspaceIds === [] || ! Schema::connection('monitor')->hasTable('user_workspace')) {
            return [];
        }

        $legacyUserIds = $this->identities->sourceIdsFor($user, 'monitor');
        if (count($legacyUserIds) !== 1) {
            return [];
        }

        return DB::connection('monitor')->table('user_workspace')
            ->whereIn('workspace_id', $workspaceIds)
            ->where('user_id', $legacyUserIds[0])
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('workspace_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * @param  array{project: CoreProject, environment: Environment, label: string, workspace_id: int}|array{project: CoreProject, environment: Environment, label: string, workspace_id: int, monitor: Monitor}  $mapping
     */
    private function runForAlertDelivery(
        AlertDelivery $delivery,
        Incident $incident,
        array $mapping,
        CoreWorkspace $workspace,
        bool $canManage,
    ): ProjectWorkflowRun {
        $status = $delivery->status;
        $state = match ($status) {
            AlertDeliveryStatus::Queued => ProjectWorkflowStepState::Pending,
            AlertDeliveryStatus::Sending, AlertDeliveryStatus::Retrying => ProjectWorkflowStepState::Processing,
            AlertDeliveryStatus::Accepted => ProjectWorkflowStepState::Delivered,
            AlertDeliveryStatus::Failed => ProjectWorkflowStepState::Failed,
            AlertDeliveryStatus::Uncertain => ProjectWorkflowStepState::Unknown,
            AlertDeliveryStatus::Cancelled => ProjectWorkflowStepState::Discarded,
        };
        $recordedAt = $delivery->created_at?->toImmutable()->utc()
            ?? $delivery->updated_at?->toImmutable()->utc()
            ?? CarbonImmutable::now('UTC');
        $resultUrl = $canManage && Route::has('monitor.alert-deliveries.show')
            ? route('monitor.alert-deliveries.show', [
                'alertDelivery' => $delivery->getKey(),
                'workspace_id' => $mapping['workspace_id'],
            ])
            : (Route::has('monitor.incidents.show') ? route('monitor.incidents.show', [
                'incident' => $incident->getKey(),
                'workspace_id' => $mapping['workspace_id'],
            ]) : null);
        $completedAt = match ($status) {
            AlertDeliveryStatus::Accepted => $delivery->accepted_at?->toImmutable()->utc(),
            AlertDeliveryStatus::Failed => $delivery->failed_at?->toImmutable()->utc(),
            AlertDeliveryStatus::Cancelled => $delivery->updated_at?->toImmutable()->utc(),
            default => null,
        };
        $attemptedAt = match ($status) {
            AlertDeliveryStatus::Sending => $delivery->updated_at?->toImmutable()->utc(),
            AlertDeliveryStatus::Accepted => $delivery->accepted_at?->toImmutable()->utc(),
            AlertDeliveryStatus::Failed => $delivery->failed_at?->toImmutable()->utc(),
            default => null,
        };
        $project = $mapping['project'];

        return new ProjectWorkflowRun(
            key: 'monitor:alert-delivery:'.$delivery->getKey(),
            title: __('Monitor alert delivery'),
            recordedAt: $recordedAt,
            projectId: (string) $project->getKey(),
            projectName: $project->name,
            projectUrl: route('core.projects.show', [$workspace, $project]),
            steps: [new ProjectWorkflowStep(
                product: 'monitor',
                productLabel: (string) config('platform.products.monitor.label', __('Monitor')),
                title: __(':environment alert notification', ['environment' => $mapping['label']]),
                detail: match ($state) {
                    ProjectWorkflowStepState::Pending => __('The alert notification is queued for delivery.'),
                    ProjectWorkflowStepState::Processing => __('The alert notification is being sent.'),
                    ProjectWorkflowStepState::Delivered => __('The notification provider accepted this alert.'),
                    ProjectWorkflowStepState::Failed => __('Alert notification delivery failed. Open Monitor for authorized details.'),
                    ProjectWorkflowStepState::Discarded => __('The alert notification was canceled.'),
                    default => __('The delivery outcome is uncertain. Open Monitor to review its current status.'),
                },
                state: $state,
                recordedAt: $recordedAt,
                attemptedAt: $attemptedAt,
                completedAt: $completedAt,
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'],
                environmentName: $mapping['canonical_environment_name'] ?: $mapping['label'],
            )],
        );
    }
}
