<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\DeploymentObservation;
use App\Modules\Deployer\Models\Environment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Read revision-bound post-deployment observation state through mapped environments. */
final class DeployerDeploymentObservationActivity
{
    public function __construct(private readonly DeployerOperationalResourceMap $resourceMap) {}

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forMappedEnvironments(array $mappedEnvironments, Workspace $workspace, int $limit): Collection
    {
        if ($mappedEnvironments === []
            || ! Schema::connection('deployer')->hasTable('deployment_observations')
            || ! Schema::connection('deployer')->hasTable('builds')
            || ! Schema::connection('deployer')->hasTable('environments')
            || ! Schema::connection('deployer')->hasTable('websites')) {
            return collect();
        }

        $websiteMappings = $this->resourceMap->websites($mappedEnvironments);

        if ($websiteMappings === []) {
            return collect();
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays(30);

        return DeploymentObservation::query()
            ->whereHas('build', fn ($builds) => $builds->whereIn('environment_id', array_keys($mappedEnvironments)))
            ->with('build:id,environment_id')
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('status', DeploymentObservation::ACTIVE_STATUSES)
                    ->orWhere('updated_at', '>=', $cutoff);
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get([
                'id',
                'build_id',
                'website_id',
                'status',
                'started_at',
                'lease_expires_at',
                'completed_at',
                'created_at',
                'updated_at',
            ])
            ->map(function (DeploymentObservation $observation) use ($mappedEnvironments, $websiteMappings, $workspace): ?ProjectWorkflowRun {
                $environmentId = (string) $observation->build?->environment_id;
                $environmentMapping = $mappedEnvironments[$environmentId] ?? null;
                $mapping = $websiteMappings[(string) $observation->website_id] ?? null;

                if ($environmentMapping === null
                    || $mapping === null
                    || $environmentMapping['environment']->website_id === null
                    || (string) $environmentMapping['environment']->website_id !== (string) $observation->website_id
                    || (string) $environmentMapping['project']->getKey() !== (string) $mapping['project']->getKey()
                    || $environmentMapping['organization_id'] !== $mapping['organization_id']) {
                    return null;
                }

                return $this->run($observation, $environmentMapping, $workspace);
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array{project: CoreProject, environment: Environment, label: string, organization_id: int}  $mapping
     */
    private function run(DeploymentObservation $observation, array $mapping, Workspace $workspace): ProjectWorkflowRun
    {
        $state = match ($observation->status) {
            DeploymentObservation::STATUS_PENDING => ProjectWorkflowStepState::Pending,
            DeploymentObservation::STATUS_OBSERVING => $observation->lease_expires_at?->isFuture()
                ? ProjectWorkflowStepState::Processing
                : ProjectWorkflowStepState::Unknown,
            DeploymentObservation::STATUS_HEALTHY => ProjectWorkflowStepState::Succeeded,
            DeploymentObservation::STATUS_FAILED,
            DeploymentObservation::STATUS_EXPIRED => ProjectWorkflowStepState::Failed,
            DeploymentObservation::STATUS_SUPERSEDED => ProjectWorkflowStepState::Discarded,
            default => ProjectWorkflowStepState::Unknown,
        };
        $recordedAt = $observation->created_at?->toImmutable()->utc()
            ?? $observation->updated_at?->toImmutable()->utc()
            ?? CarbonImmutable::now('UTC');
        $resultUrl = Route::has('builds.show')
            ? route('builds.show', [
                'build' => $observation->build_id,
                'organization_id' => $mapping['organization_id'],
            ])
            : null;

        return new ProjectWorkflowRun(
            key: 'deployer:deployment-observation:'.$observation->getKey(),
            title: __('Post-deployment health observation'),
            recordedAt: $recordedAt,
            projectId: (string) $mapping['project']->getKey(),
            projectName: $mapping['project']->name,
            projectUrl: route('core.projects.show', [$workspace, $mapping['project']]),
            steps: [new ProjectWorkflowStep(
                product: 'deployer',
                productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                title: __(':environment deployment health window', ['environment' => $mapping['label']]),
                detail: $this->detail($observation, $state),
                state: $state,
                recordedAt: $recordedAt,
                attemptedAt: $observation->started_at?->toImmutable()->utc(),
                completedAt: $observation->completed_at?->toImmutable()->utc(),
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'] ?? null,
                environmentName: $mapping['canonical_environment_name'] ?? $mapping['label'],
            )],
        );
    }

    private function detail(DeploymentObservation $observation, ProjectWorkflowStepState $state): string
    {
        return match ($observation->status) {
            DeploymentObservation::STATUS_PENDING => __('Deployer has queued revision-bound website health checks.'),
            DeploymentObservation::STATUS_OBSERVING => $state === ProjectWorkflowStepState::Processing
                ? __('Deployer is checking the website after deployment.')
                : __('The Deployer observation lease expired; its current progress is unavailable.'),
            DeploymentObservation::STATUS_HEALTHY => __('Deployer confirmed website health during the observation window.'),
            DeploymentObservation::STATUS_FAILED => __('Deployer could not confirm website health. Open the build for authorized details.'),
            DeploymentObservation::STATUS_EXPIRED => __('The observation window expired before Deployer could confirm website health.'),
            DeploymentObservation::STATUS_SUPERSEDED => __('A later deployment replaced this observation before it completed.'),
            default => __('The Deployer observation state is unavailable.'),
        };
    }
}
