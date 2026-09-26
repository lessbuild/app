<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\LoadBalancer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Project bounded load-balancer configuration state through mapped environments. */
final class DeployerLoadBalancerActivity
{
    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int, canonical_environment_id?: string|null, canonical_environment_name?: string|null}>  $mappedEnvironments
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forMappedEnvironments(array $mappedEnvironments, Workspace $workspace, int $limit): Collection
    {
        if ($mappedEnvironments === [] || ! Schema::connection('deployer')->hasTable('load_balancers')) {
            return collect();
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays(30);
        $resultLimit = max(1, min(100, $limit));

        return LoadBalancer::query()
            ->whereIn('environment_id', array_keys($mappedEnvironments))
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('status', ['pending', 'removing'])
                    ->orWhere(function ($failed) use ($cutoff): void {
                        $failed->whereIn('status', ['failed', 'removal_failed'])->where('updated_at', '>=', $cutoff);
                    })
                    ->orWhere(function ($active) use ($cutoff): void {
                        $active->where('status', 'active')
                            ->where(function ($recent) use ($cutoff): void {
                                $recent->where('applied_at', '>=', $cutoff)
                                    ->orWhere(function ($legacy) use ($cutoff): void {
                                        $legacy->whereNull('applied_at')->where('updated_at', '>=', $cutoff);
                                    });
                            });
                    })
                    ->orWhere(function ($unknown) use ($cutoff): void {
                        $unknown->whereNotIn('status', ['pending', 'removing', 'failed', 'removal_failed', 'active'])
                            ->where('updated_at', '>=', $cutoff);
                    });
            })
            ->with('server:id,organization_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($resultLimit)
            ->get(['id', 'organization_id', 'environment_id', 'server_id', 'status', 'applied_at', 'created_at', 'updated_at'])
            ->map(fn (LoadBalancer $loadBalancer): ?ProjectWorkflowRun => $this->runFor($loadBalancer, $mappedEnvironments, $workspace))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int, canonical_environment_id?: string|null, canonical_environment_name?: string|null}>  $mappings
     */
    private function runFor(LoadBalancer $loadBalancer, array $mappings, Workspace $workspace): ?ProjectWorkflowRun
    {
        $mapping = $mappings[(string) $loadBalancer->environment_id] ?? null;

        if ($mapping === null
            || (int) $loadBalancer->organization_id !== $mapping['organization_id']
            || $loadBalancer->server === null
            || (int) $loadBalancer->server->organization_id !== $mapping['organization_id']) {
            return null;
        }

        $state = match ((string) $loadBalancer->status) {
            'pending', 'removing' => ProjectWorkflowStepState::Pending,
            'active' => ProjectWorkflowStepState::Succeeded,
            'failed', 'removal_failed' => ProjectWorkflowStepState::Failed,
            default => ProjectWorkflowStepState::Unknown,
        };
        $recordedAt = match ($state) {
            ProjectWorkflowStepState::Succeeded => $loadBalancer->applied_at?->toImmutable()->utc()
                ?? $loadBalancer->updated_at?->toImmutable()->utc(),
            ProjectWorkflowStepState::Failed, ProjectWorkflowStepState::Unknown => $loadBalancer->updated_at?->toImmutable()->utc(),
            default => $loadBalancer->status === 'removing'
                ? $loadBalancer->updated_at?->toImmutable()->utc()
                : $loadBalancer->created_at?->toImmutable()->utc(),
        } ?? CarbonImmutable::now('UTC');
        $resultUrl = Route::has('load-balancers.index')
            ? route('load-balancers.index', ['organization_id' => $mapping['organization_id']])
            : null;

        return new ProjectWorkflowRun(
            key: 'deployer:load-balancer:'.$loadBalancer->getKey(),
            title: __('Load-balancer configuration'),
            recordedAt: $recordedAt,
            projectId: (string) $mapping['project']->getKey(),
            projectName: $mapping['project']->name,
            projectUrl: route('core.projects.show', [$workspace, $mapping['project']]),
            steps: [new ProjectWorkflowStep(
                product: 'deployer',
                productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                title: __(':environment load balancer', ['environment' => $mapping['label']]),
                detail: $this->detail($state, (string) $loadBalancer->status),
                state: $state,
                recordedAt: $recordedAt,
                completedAt: in_array($state, [ProjectWorkflowStepState::Succeeded, ProjectWorkflowStepState::Failed], true)
                    ? $recordedAt
                    : null,
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'] ?? null,
                environmentName: $mapping['canonical_environment_name'] ?? $mapping['label'],
            )],
        );
    }

    private function detail(ProjectWorkflowStepState $state, string $loadBalancerStatus): string
    {
        if ($loadBalancerStatus === 'removing') {
            return __('Remote load-balancer cleanup is in progress.');
        }

        if ($loadBalancerStatus === 'removal_failed') {
            return __('Remote load-balancer cleanup failed. Open Deployer to retry.');
        }

        return match ($state) {
            ProjectWorkflowStepState::Pending => __('The load-balancer configuration is waiting to be applied.'),
            ProjectWorkflowStepState::Succeeded => __('The load-balancer configuration was applied successfully.'),
            ProjectWorkflowStepState::Failed => __('The load-balancer configuration failed. Open Deployer for authorized details.'),
            default => __('The load-balancer configuration state is unavailable. Open Deployer for current information.'),
        };
    }
}
