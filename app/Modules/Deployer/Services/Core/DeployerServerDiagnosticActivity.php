<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\ServerDiagnosticSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Read bounded server diagnostic state through exact environment and project mappings. */
final class DeployerServerDiagnosticActivity
{
    public function __construct(private readonly DeployerOperationalResourceMap $resourceMap) {}

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forMappedEnvironments(array $mappedEnvironments, Workspace $workspace, int $limit): Collection
    {
        if ($mappedEnvironments === []
            || ! Schema::connection('deployer')->hasTable('server_diagnostic_snapshots')
            || ! Schema::connection('deployer')->hasTable('servers')
            || ! Schema::connection('deployer')->hasTable('environments')) {
            return collect();
        }

        $safeServers = $this->resourceMap->servers($mappedEnvironments);

        if ($safeServers === []) {
            return collect();
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays(30);

        return ServerDiagnosticSnapshot::query()
            ->whereIn('server_id', array_keys($safeServers))
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('status', ServerDiagnosticSnapshot::ACTIVE_STATUSES)
                    ->orWhere('updated_at', '>=', $cutoff);
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get(['id', 'server_id', 'status', 'started_at', 'finished_at', 'lease_expires_at', 'created_at', 'updated_at'])
            ->map(function (ServerDiagnosticSnapshot $snapshot) use ($safeServers, $workspace): ?ProjectWorkflowRun {
                $serverId = (string) $snapshot->server_id;
                $mapping = $safeServers[$serverId] ?? null;

                if ($mapping === null) {
                    return null;
                }

                return $this->run($snapshot, $mapping, $workspace);
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array{project: CoreProject, environment: Environment, label: string, organization_id: int}  $mapping
     */
    private function run(ServerDiagnosticSnapshot $snapshot, array $mapping, Workspace $workspace): ProjectWorkflowRun
    {
        $state = match ($snapshot->status) {
            ServerDiagnosticSnapshot::STATUS_QUEUED => ProjectWorkflowStepState::Pending,
            ServerDiagnosticSnapshot::STATUS_RUNNING => $snapshot->lease_expires_at !== null && $snapshot->lease_expires_at->isFuture()
                ? ProjectWorkflowStepState::Processing
                : ProjectWorkflowStepState::Unknown,
            ServerDiagnosticSnapshot::STATUS_READY => ProjectWorkflowStepState::Succeeded,
            ServerDiagnosticSnapshot::STATUS_FAILED => ProjectWorkflowStepState::Failed,
            default => ProjectWorkflowStepState::Unknown,
        };
        $recordedAt = $snapshot->created_at?->toImmutable()->utc() ?? CarbonImmutable::now('UTC');
        $resultUrl = Route::has('servers.show')
            ? route('servers.show', [
                'server' => $snapshot->server_id,
                'organization_id' => $mapping['organization_id'],
            ])
            : null;

        return new ProjectWorkflowRun(
            key: 'deployer:server-diagnostic:'.$snapshot->getKey(),
            title: __('Server diagnostic'),
            recordedAt: $recordedAt,
            projectId: (string) $mapping['project']->getKey(),
            projectName: $mapping['project']->name,
            projectUrl: route('core.projects.show', [$workspace, $mapping['project']]),
            steps: [new ProjectWorkflowStep(
                product: 'deployer',
                productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                title: __('Server diagnostic'),
                detail: $this->detail($state),
                state: $state,
                recordedAt: $recordedAt,
                attemptedAt: $snapshot->started_at?->toImmutable()->utc(),
                completedAt: $snapshot->finished_at?->toImmutable()->utc(),
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'] ?? null,
                environmentName: $mapping['canonical_environment_name'] ?? $mapping['label'],
            )],
        );
    }

    private function detail(ProjectWorkflowStepState $state): string
    {
        return match ($state) {
            ProjectWorkflowStepState::Pending => __('Deployer has queued a server diagnostic.'),
            ProjectWorkflowStepState::Processing => __('Deployer is collecting server diagnostics.'),
            ProjectWorkflowStepState::Succeeded => __('Deployer completed the server diagnostic. Open the server for authorized findings.'),
            ProjectWorkflowStepState::Failed => __('Deployer could not complete the server diagnostic. Open the server for authorized details.'),
            default => __('The server diagnostic state is unavailable. Open the server for current information.'),
        };
    }
}
