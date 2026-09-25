<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Actions\Server\CollectServerLogAction;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\ServerLogSnapshot;
use App\Modules\Deployer\Models\WebsiteLogSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Read bounded log-refresh state through exact Deployer resource and Core project mappings. */
final class DeployerLogSnapshotActivity
{
    public function __construct(private readonly DeployerOperationalResourceMap $resourceMap) {}

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forMappedEnvironments(array $mappedEnvironments, Workspace $workspace, int $limit): Collection
    {
        if ($mappedEnvironments === []) {
            return collect();
        }

        $queryLimit = max(1, min(100, $limit));

        return $this->websiteLogRefreshes($mappedEnvironments, $workspace, $queryLimit)
            ->concat($this->serverLogRefreshes($mappedEnvironments, $workspace, $queryLimit))
            ->values();
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return Collection<int, ProjectWorkflowRun>
     */
    private function websiteLogRefreshes(array $mappedEnvironments, Workspace $workspace, int $limit): Collection
    {
        if (! Schema::connection('deployer')->hasTable('website_log_snapshots')
            || ! Schema::connection('deployer')->hasTable('websites')
            || ! Schema::connection('deployer')->hasTable('environments')) {
            return collect();
        }

        $mappings = $this->resourceMap->websites($mappedEnvironments);

        if ($mappings === []) {
            return collect();
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays(30);

        return WebsiteLogSnapshot::query()
            ->whereIn('website_id', array_keys($mappings))
            ->whereIn('type', WebsiteLogSnapshot::TYPES)
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('status', [WebsiteLogSnapshot::STATUS_QUEUED, WebsiteLogSnapshot::STATUS_REFRESHING])
                    ->orWhere('updated_at', '>=', $cutoff);
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'website_id', 'type', 'status', 'refreshed_at', 'created_at', 'updated_at'])
            ->map(function (WebsiteLogSnapshot $snapshot) use ($mappings, $workspace): ?ProjectWorkflowRun {
                $mapping = $mappings[(string) $snapshot->website_id] ?? null;

                if ($mapping === null) {
                    return null;
                }

                $resultUrl = Route::has('websites.show')
                    ? route('websites.show', [
                        'website' => $snapshot->website_id,
                        'organization_id' => $mapping['organization_id'],
                    ])
                    : null;

                return $this->run(
                    key: 'deployer:website-log-refresh:'.$snapshot->getKey(),
                    title: __('Website log refresh'),
                    productTitle: __(':environment :category logs', [
                        'environment' => $mapping['label'],
                        'category' => $this->categoryLabel((string) $snapshot->type),
                    ]),
                    status: (string) $snapshot->status,
                    recordedAt: $snapshot->updated_at ?? $snapshot->created_at,
                    completedAt: $snapshot->status === WebsiteLogSnapshot::STATUS_READY
                        ? $snapshot->refreshed_at
                        : ($snapshot->status === WebsiteLogSnapshot::STATUS_FAILED ? $snapshot->updated_at : null),
                    resultUrl: $resultUrl,
                    mapping: $mapping,
                    workspace: $workspace,
                    subject: __('runtime logs'),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return Collection<int, ProjectWorkflowRun>
     */
    private function serverLogRefreshes(array $mappedEnvironments, Workspace $workspace, int $limit): Collection
    {
        if (! Schema::connection('deployer')->hasTable('server_log_snapshots')
            || ! Schema::connection('deployer')->hasTable('servers')
            || ! Schema::connection('deployer')->hasTable('environments')) {
            return collect();
        }

        $mappings = $this->resourceMap->servers($mappedEnvironments);

        if ($mappings === []) {
            return collect();
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays(30);

        return ServerLogSnapshot::query()
            ->whereIn('server_id', array_keys($mappings))
            ->whereIn('type', CollectServerLogAction::TYPES)
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('status', [ServerLogSnapshot::STATUS_QUEUED, ServerLogSnapshot::STATUS_REFRESHING])
                    ->orWhere('updated_at', '>=', $cutoff);
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'server_id', 'type', 'status', 'refreshed_at', 'created_at', 'updated_at'])
            ->map(function (ServerLogSnapshot $snapshot) use ($mappings, $workspace): ?ProjectWorkflowRun {
                $mapping = $mappings[(string) $snapshot->server_id] ?? null;

                if ($mapping === null) {
                    return null;
                }

                $resultUrl = Route::has('servers.show')
                    ? route('servers.show', [
                        'server' => $snapshot->server_id,
                        'organization_id' => $mapping['organization_id'],
                    ])
                    : null;

                return $this->run(
                    key: 'deployer:server-log-refresh:'.$snapshot->getKey(),
                    title: __('Server log refresh'),
                    productTitle: __(':environment :category server logs', [
                        'environment' => $mapping['label'],
                        'category' => $this->categoryLabel((string) $snapshot->type),
                    ]),
                    status: (string) $snapshot->status,
                    recordedAt: $snapshot->updated_at ?? $snapshot->created_at,
                    completedAt: $snapshot->status === ServerLogSnapshot::STATUS_READY
                        ? $snapshot->refreshed_at
                        : ($snapshot->status === ServerLogSnapshot::STATUS_FAILED ? $snapshot->updated_at : null),
                    resultUrl: $resultUrl,
                    mapping: $mapping,
                    workspace: $workspace,
                    subject: __('server logs'),
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
        ?CarbonInterface $recordedAt,
        ?CarbonInterface $completedAt,
        ?string $resultUrl,
        array $mapping,
        Workspace $workspace,
        string $subject,
    ): ProjectWorkflowRun {
        $state = match ($status) {
            WebsiteLogSnapshot::STATUS_QUEUED, ServerLogSnapshot::STATUS_QUEUED => ProjectWorkflowStepState::Pending,
            WebsiteLogSnapshot::STATUS_REFRESHING, ServerLogSnapshot::STATUS_REFRESHING => ProjectWorkflowStepState::Processing,
            WebsiteLogSnapshot::STATUS_READY, ServerLogSnapshot::STATUS_READY => ProjectWorkflowStepState::Succeeded,
            WebsiteLogSnapshot::STATUS_FAILED, ServerLogSnapshot::STATUS_FAILED => ProjectWorkflowStepState::Failed,
            default => ProjectWorkflowStepState::Unknown,
        };
        $timestamp = $recordedAt?->toImmutable()->utc() ?? CarbonImmutable::now('UTC');

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
                detail: $this->detail($state, $subject),
                state: $state,
                recordedAt: $timestamp,
                completedAt: $completedAt?->toImmutable()->utc(),
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'] ?? null,
                environmentName: $mapping['canonical_environment_name'] ?? $mapping['label'],
            )],
        );
    }

    private function categoryLabel(string $type): string
    {
        return match ($type) {
            'application' => __('Application'),
            'access' => __('Access'),
            'apt' => __('APT'),
            'caddy' => __('Caddy'),
            'mysql' => __('MySQL'),
            'php' => __('PHP'),
            'provisioning' => __('Provisioning'),
            default => __('Managed'),
        };
    }

    private function detail(ProjectWorkflowStepState $state, string $subject): string
    {
        return match ($state) {
            ProjectWorkflowStepState::Pending => __('Deployer has queued a refresh for :subject.', ['subject' => $subject]),
            ProjectWorkflowStepState::Processing => __('Deployer is refreshing :subject.', ['subject' => $subject]),
            ProjectWorkflowStepState::Succeeded => __('Deployer refreshed :subject. Open the resource for authorized data.', ['subject' => $subject]),
            ProjectWorkflowStepState::Failed => __('Deployer could not refresh :subject. Open the resource for authorized details.', ['subject' => $subject]),
            default => __('The Deployer refresh state for :subject is unavailable.', ['subject' => $subject]),
        };
    }
}
