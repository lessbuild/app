<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\WebsiteHealthCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Read bounded website health-check outcomes through safe Core project mappings. */
final class DeployerWebsiteHealthCheckActivity
{
    public function __construct(private readonly DeployerOperationalResourceMap $resourceMap) {}

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forMappedEnvironments(array $mappedEnvironments, Workspace $workspace, int $limit): Collection
    {
        if ($mappedEnvironments === []
            || ! Schema::connection('deployer')->hasTable('website_health_checks')
            || ! Schema::connection('deployer')->hasTable('websites')
            || ! Schema::connection('deployer')->hasTable('environments')) {
            return collect();
        }

        $websiteMappings = $this->resourceMap->websites($mappedEnvironments);

        if ($websiteMappings === []) {
            return collect();
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays(30);

        return WebsiteHealthCheck::query()
            ->whereIn('website_id', array_keys($websiteMappings))
            ->where('checked_at', '>=', $cutoff)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get(['id', 'website_id', 'successful', 'source', 'checked_at'])
            ->map(function (WebsiteHealthCheck $check) use ($websiteMappings, $workspace): ?ProjectWorkflowRun {
                $mapping = $websiteMappings[(string) $check->website_id] ?? null;

                if ($mapping === null) {
                    return null;
                }

                $recordedAt = $check->checked_at?->toImmutable()->utc() ?? CarbonImmutable::now('UTC');
                $state = $check->successful
                    ? ProjectWorkflowStepState::Succeeded
                    : ProjectWorkflowStepState::Failed;
                $source = $check->source === WebsiteHealthCheck::SOURCE_MANUAL
                    ? __('Manual')
                    : __('Automatic');

                return new ProjectWorkflowRun(
                    key: 'deployer:website-health-check:'.$check->getKey(),
                    title: __('Website health check'),
                    recordedAt: $recordedAt,
                    projectId: (string) $mapping['project']->getKey(),
                    projectName: $mapping['project']->name,
                    projectUrl: route('core.projects.show', [$workspace, $mapping['project']]),
                    steps: [new ProjectWorkflowStep(
                        product: 'deployer',
                        productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                        title: __(':environment :source health check', [
                            'environment' => $mapping['label'],
                            'source' => $source,
                        ]),
                        detail: $this->detail($state),
                        state: $state,
                        recordedAt: $recordedAt,
                        completedAt: $recordedAt,
                        resultUrl: Route::has('websites.health-checks.index')
                            ? route('websites.health-checks.index', [
                                'website' => $check->website_id,
                                'organization_id' => $mapping['organization_id'],
                            ])
                            : null,
                        environmentId: $mapping['canonical_environment_id'] ?? null,
                        environmentName: $mapping['canonical_environment_name'] ?? $mapping['label'],
                    )],
                );
            })
            ->filter()
            ->values();
    }

    private function detail(ProjectWorkflowStepState $state): string
    {
        return $state === ProjectWorkflowStepState::Succeeded
            ? __('Deployer completed a website health check.')
            : __('Deployer reported a website health check failure. Open the website for authorized details.');
    }
}
