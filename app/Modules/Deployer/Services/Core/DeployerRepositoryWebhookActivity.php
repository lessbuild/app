<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\RepositoryWebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Read bounded repository webhook state through an unambiguous mapped project. */
final class DeployerRepositoryWebhookActivity
{
    public function __construct(private readonly DeployerOperationalResourceMap $resourceMap) {}

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forMappedEnvironments(array $mappedEnvironments, Workspace $workspace, int $limit): Collection
    {
        if ($mappedEnvironments === []
            || ! Schema::connection('deployer')->hasTable('repository_webhook_deliveries')
            || ! Schema::connection('deployer')->hasTable('repositories')
            || ! Schema::connection('deployer')->hasTable('websites')) {
            return collect();
        }

        $websiteMappings = $this->resourceMap->websites($mappedEnvironments);

        if ($websiteMappings === []) {
            return collect();
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays(30);

        return RepositoryWebhookDelivery::query()
            // Builds are already represented by their canonical deployment row.
            ->whereNull('build_id')
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('status', [
                    RepositoryWebhookDelivery::STATUS_RECEIVED,
                    RepositoryWebhookDelivery::STATUS_QUEUED,
                    RepositoryWebhookDelivery::STATUS_PENDING,
                ])->orWhere(function ($terminal) use ($cutoff): void {
                    $terminal->whereIn('status', [
                        RepositoryWebhookDelivery::STATUS_SKIPPED,
                        RepositoryWebhookDelivery::STATUS_UNAVAILABLE,
                        RepositoryWebhookDelivery::STATUS_SUPERSEDED,
                    ])->where('updated_at', '>=', $cutoff);
                });
            })
            ->whereHas('repository', function ($repositories) use ($websiteMappings): void {
                $repositories->where(function ($mappedRepositories) use ($websiteMappings): void {
                    foreach ($websiteMappings as $websiteId => $mapping) {
                        $mappedRepositories->orWhere(function ($repository) use ($websiteId, $mapping): void {
                            $repository
                                ->where('website_id', $websiteId)
                                ->where('organization_id', $mapping['organization_id'])
                                ->whereHas('website', fn ($website) => $website
                                    ->where('websites.organization_id', $mapping['organization_id'])
                                    ->whereNull('websites.deleted_at'));
                        });
                    }
                });
            })
            ->with('repository:id,website_id,organization_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get(['id', 'repository_id', 'status', 'build_id', 'created_at', 'updated_at'])
            ->map(function (RepositoryWebhookDelivery $delivery) use ($websiteMappings, $workspace): ?ProjectWorkflowRun {
                $repository = $delivery->repository;
                $mapping = $repository === null ? null : ($websiteMappings[(string) $repository->website_id] ?? null);

                if ($mapping === null || (int) $repository->organization_id !== $mapping['organization_id']) {
                    return null;
                }

                return $this->run($delivery, $repository, $mapping, $workspace);
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array{project: CoreProject, environment: Environment, label: string, organization_id: int}  $mapping
     */
    private function run(
        RepositoryWebhookDelivery $delivery,
        Repository $repository,
        array $mapping,
        Workspace $workspace,
    ): ProjectWorkflowRun {
        $status = (string) $delivery->status;
        $state = match ($status) {
            RepositoryWebhookDelivery::STATUS_QUEUED,
            RepositoryWebhookDelivery::STATUS_PENDING => ProjectWorkflowStepState::Pending,
            RepositoryWebhookDelivery::STATUS_UNAVAILABLE => ProjectWorkflowStepState::Blocked,
            RepositoryWebhookDelivery::STATUS_SKIPPED,
            RepositoryWebhookDelivery::STATUS_SUPERSEDED => ProjectWorkflowStepState::Discarded,
            default => ProjectWorkflowStepState::Unknown,
        };
        $recordedAt = $delivery->created_at?->toImmutable()->utc() ?? CarbonImmutable::now('UTC');
        $resultUrl = Route::has('repositories.show')
            ? route('repositories.show', [
                'repository' => $repository->getKey(),
                'organization_id' => $mapping['organization_id'],
            ])
            : null;

        return new ProjectWorkflowRun(
            key: 'deployer:webhook-delivery:'.$delivery->getKey(),
            title: __('Repository delivery'),
            recordedAt: $recordedAt,
            projectId: (string) $mapping['project']->getKey(),
            projectName: $mapping['project']->name,
            projectUrl: route('core.projects.show', [$workspace, $mapping['project']]),
            steps: [new ProjectWorkflowStep(
                product: 'deployer',
                productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                title: __(':environment repository delivery', ['environment' => $mapping['label']]),
                detail: $this->detail($state),
                state: $state,
                recordedAt: $recordedAt,
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'] ?? null,
                environmentName: $mapping['canonical_environment_name'] ?? $mapping['label'],
            )],
        );
    }

    private function detail(ProjectWorkflowStepState $state): string
    {
        return match ($state) {
            ProjectWorkflowStepState::Pending => __('A repository delivery is waiting for Deployer to start a release.'),
            ProjectWorkflowStepState::Blocked => __('Deployer could not process this repository delivery. Open the repository for authorized details.'),
            ProjectWorkflowStepState::Discarded => __('This repository delivery did not create a release under the configured deployment rules.'),
            default => __('Deployer has received this repository event and is checking whether it can start a release.'),
        };
    }
}
