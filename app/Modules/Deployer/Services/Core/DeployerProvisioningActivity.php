<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\ServerProvisioningPlan;
use App\Modules\Deployer\Services\WebsiteProvisioningPlan;
use App\Modules\Deployer\Services\WebsiteProvisioningTimeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Build safe Core activity summaries from Deployer's current provisioning records.
 *
 * Deployer remains authoritative for provisioning state, while Core supplies
 * only environments that already passed current project and local access checks.
 */
final class DeployerProvisioningActivity
{
    public function __construct(
        private readonly ServerProvisioningPlan $serverPlan,
        private readonly WebsiteProvisioningPlan $websitePlan,
    ) {}

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forMappedEnvironments(array $mappedEnvironments, Workspace $workspace, int $limit): Collection
    {
        $serverIds = collect($mappedEnvironments)
            ->map(fn (array $mapping): ?string => $mapping['environment']->server_id === null ? null : (string) $mapping['environment']->server_id)
            ->concat(collect($mappedEnvironments)
                ->map(fn (array $mapping): ?string => $mapping['environment']->website?->server_id === null
                    ? null
                    : (string) $mapping['environment']->website->server_id))
            ->filter()
            ->unique()
            ->values();
        $websiteIds = collect($mappedEnvironments)
            ->map(fn (array $mapping): ?string => $mapping['environment']->website_id === null ? null : (string) $mapping['environment']->website_id)
            ->filter()
            ->unique()
            ->values();

        $recentSuccessCutoff = now()->subDays(30);
        $queryLimit = max(1, min(100, $limit));

        $servers = $serverIds->isEmpty()
            ? collect()
            : Server::query()
                ->whereIn('id', $serverIds)
                ->where(fn ($query) => $query
                    ->whereIn('provisioning_status', [Server::STATUS_QUEUED, Server::STATUS_WAITING_FOR_IP, Server::STATUS_PROVISIONING, Server::STATUS_FAILED])
                    ->orWhere(fn ($active) => $active
                        ->where('provisioning_status', Server::STATUS_ACTIVE)
                        ->where(fn ($recent) => $recent
                            ->where('provisioned_at', '>=', $recentSuccessCutoff)
                            ->orWhere(fn ($legacy) => $legacy->whereNull('provisioned_at')->where('updated_at', '>=', $recentSuccessCutoff))))
                    ->orWhere(fn ($unknown) => $unknown
                        ->whereNotIn('provisioning_status', [Server::STATUS_QUEUED, Server::STATUS_WAITING_FOR_IP, Server::STATUS_PROVISIONING, Server::STATUS_FAILED, Server::STATUS_ACTIVE])
                        ->where('updated_at', '>=', $recentSuccessCutoff)))
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit($queryLimit)
                ->get(['id', 'organization_id', 'name', 'type', 'setup_stage', 'provisioning_status', 'provisioned_at', 'created_at', 'updated_at'])
                ->keyBy(fn (Server $server): string => (string) $server->getKey());

        $websites = $websiteIds->isEmpty()
            ? collect()
            : Website::query()
                ->whereIn('id', $websiteIds)
                ->where(fn ($query) => $query
                    ->whereIn('provisioning_status', [Website::STATUS_QUEUED, Website::STATUS_PROVISIONING, Website::STATUS_FAILED, WebsiteProvisioningTimeline::STATUS_CANCELED])
                    ->orWhere(fn ($active) => $active
                        ->where('provisioning_status', Website::STATUS_ACTIVE)
                        ->where(fn ($recent) => $recent
                            ->where('provisioned_at', '>=', $recentSuccessCutoff)
                            ->orWhere(fn ($legacy) => $legacy->whereNull('provisioned_at')->where('updated_at', '>=', $recentSuccessCutoff))))
                    ->orWhere(fn ($unknown) => $unknown
                        ->whereNotIn('provisioning_status', [Website::STATUS_QUEUED, Website::STATUS_PROVISIONING, Website::STATUS_FAILED, WebsiteProvisioningTimeline::STATUS_CANCELED, Website::STATUS_ACTIVE])
                        ->where('updated_at', '>=', $recentSuccessCutoff)))
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit($queryLimit)
                ->get(['id', 'organization_id', 'server_id', 'name', 'setup_stage', 'provisioning_status', 'provisioned_at', 'created_at', 'updated_at'])
                ->keyBy(fn (Website $website): string => (string) $website->getKey());

        $runs = collect();

        foreach ($mappedEnvironments as $mapping) {
            $environment = $mapping['environment'];
            $server = $environment->server_id === null ? null : $servers->get((string) $environment->server_id);

            if ($server instanceof Server && (int) $server->organization_id === $mapping['organization_id']) {
                $run = $this->serverRun($server, $mapping, $workspace);
                if ($run !== null) {
                    $runs->push($run);
                }
            }

            $website = $environment->website_id === null ? null : $websites->get((string) $environment->website_id);
            $websiteServer = $website?->server_id === null ? null : $servers->get((string) $website->server_id);
            $websiteMatchesEnvironmentServer = $environment->server_id === null
                || ($website !== null && (string) $website->server_id === (string) $environment->server_id);

            if ($website instanceof Website
                && (int) $website->organization_id === $mapping['organization_id']
                && $websiteServer instanceof Server
                && (int) $websiteServer->organization_id === $mapping['organization_id']
                && $websiteMatchesEnvironmentServer) {
                $run = $this->websiteRun($website, $mapping, $workspace);
                if ($run !== null) {
                    $runs->push($run);
                }
            }
        }

        return $runs;
    }

    /**
     * @param  array{project: CoreProject, environment: Environment, label: string, organization_id: int}  $mapping
     */
    private function serverRun(Server $server, array $mapping, Workspace $workspace): ?ProjectWorkflowRun
    {
        return $this->provisioningRun(
            resource: $server,
            mapping: $mapping,
            workspace: $workspace,
            kind: 'server',
            finalStage: $this->serverPlan->finalStage((string) $server->getRawOriginal('type')),
            status: (string) $server->provisioning_status,
            canceledStatus: null,
        );
    }

    /**
     * @param  array{project: CoreProject, environment: Environment, label: string, organization_id: int}  $mapping
     */
    private function websiteRun(Website $website, array $mapping, Workspace $workspace): ?ProjectWorkflowRun
    {
        return $this->provisioningRun(
            resource: $website,
            mapping: $mapping,
            workspace: $workspace,
            kind: 'website',
            finalStage: $this->websitePlan->finalStage(),
            status: (string) $website->provisioning_status,
            canceledStatus: WebsiteProvisioningTimeline::STATUS_CANCELED,
        );
    }

    /**
     * @param  array{project: CoreProject, environment: Environment, label: string, organization_id: int}  $mapping
     */
    private function provisioningRun(
        Server|Website $resource,
        array $mapping,
        Workspace $workspace,
        string $kind,
        int $finalStage,
        string $status,
        ?string $canceledStatus,
    ): ?ProjectWorkflowRun {
        $progress = max(0, min($finalStage, (int) $resource->setup_stage));
        $state = match (true) {
            $status === 'queued', $status === Server::STATUS_WAITING_FOR_IP => ProjectWorkflowStepState::Pending,
            $status === Server::STATUS_PROVISIONING => ProjectWorkflowStepState::Processing,
            $status === Server::STATUS_FAILED => ProjectWorkflowStepState::Failed,
            $canceledStatus !== null && $status === $canceledStatus => ProjectWorkflowStepState::Discarded,
            $status === 'active' && ($resource->provisioned_at !== null || $progress >= $finalStage) => ProjectWorkflowStepState::Succeeded,
            $status === 'active' => ProjectWorkflowStepState::Unknown,
            default => ProjectWorkflowStepState::Unknown,
        };

        $recordedAt = match ($state) {
            ProjectWorkflowStepState::Succeeded => $resource->provisioned_at?->toImmutable()->utc()
                ?? $resource->updated_at?->toImmutable()->utc()
                ?? $resource->created_at?->toImmutable()->utc(),
            ProjectWorkflowStepState::Pending => $resource->created_at?->toImmutable()->utc()
                ?? $resource->updated_at?->toImmutable()->utc(),
            default => $resource->updated_at?->toImmutable()->utc()
                ?? $resource->created_at?->toImmutable()->utc(),
        };

        if ($recordedAt === null) {
            return null;
        }

        $resultRoute = $kind === 'server' ? 'servers.show' : 'websites.show';
        $resultUrl = Route::has($resultRoute)
            ? route($resultRoute, [
                $kind => $resource->getKey(),
                'organization_id' => $mapping['organization_id'],
            ])
            : null;
        $productLabel = (string) config('platform.products.deployer.label', __('Deployer'));
        $label = $kind === 'server' ? __('server') : __('website');
        $title = $kind === 'server' ? __('Server provisioning') : __('Website provisioning');
        $attemptedAt = in_array($state, [ProjectWorkflowStepState::Processing, ProjectWorkflowStepState::Failed, ProjectWorkflowStepState::Discarded], true)
            ? $resource->updated_at?->toImmutable()->utc()
            : null;
        $completedAt = $state === ProjectWorkflowStepState::Succeeded
            ? ($resource->provisioned_at?->toImmutable()->utc() ?? $resource->updated_at?->toImmutable()->utc())
            : null;

        return new ProjectWorkflowRun(
            key: 'deployer:'.$kind.':'.$resource->getKey(),
            title: $title,
            recordedAt: $recordedAt,
            projectId: (string) $mapping['project']->getKey(),
            projectName: $mapping['project']->name,
            projectUrl: route('core.projects.show', [$workspace, $mapping['project']]),
            steps: [new ProjectWorkflowStep(
                product: 'deployer',
                productLabel: $productLabel,
                title: __(':environment :resource', [
                    'environment' => $mapping['label'],
                    'resource' => $label,
                ]),
                detail: $this->detail($status, $state, $progress, $finalStage),
                state: $state,
                recordedAt: $recordedAt,
                attemptedAt: $attemptedAt,
                completedAt: $completedAt,
                resultUrl: $resultUrl,
                environmentId: $mapping['canonical_environment_id'] ?? null,
                environmentName: $mapping['canonical_environment_name'] ?? $mapping['label'],
            )],
        );
    }

    private function detail(string $status, ProjectWorkflowStepState $state, int $progress, int $finalStage): string
    {
        return match ($state) {
            ProjectWorkflowStepState::Pending => $status === Server::STATUS_WAITING_FOR_IP
                ? __('Provisioning is waiting for an address. :completed of :total stages are recorded.', ['completed' => $progress, 'total' => $finalStage])
                : __('Provisioning is queued. :completed of :total stages are recorded.', ['completed' => $progress, 'total' => $finalStage]),
            ProjectWorkflowStepState::Processing => __('Provisioning is in progress. :completed of :total stages are recorded.', ['completed' => $progress, 'total' => $finalStage]),
            ProjectWorkflowStepState::Succeeded => __('Provisioning completed successfully. :completed of :total stages are recorded.', ['completed' => $progress, 'total' => $finalStage]),
            ProjectWorkflowStepState::Failed => __('Provisioning failed. :completed of :total stages are recorded. Open the Deployer record for authorized details.', ['completed' => $progress, 'total' => $finalStage]),
            ProjectWorkflowStepState::Discarded => __('Provisioning was canceled. :completed of :total stages are recorded.', ['completed' => $progress, 'total' => $finalStage]),
            default => __('The provisioning state is unavailable. :completed of :total stages are recorded. Open the Deployer record for current information.', ['completed' => $progress, 'total' => $finalStage]),
        };
    }
}
