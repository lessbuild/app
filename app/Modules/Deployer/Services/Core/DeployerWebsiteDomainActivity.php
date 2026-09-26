<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\WebsiteDomain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Project only mapped Deployer DNS and certificate attention without exposing domain names or errors. */
final class DeployerWebsiteDomainActivity
{
    public function __construct(private readonly DeployerOperationalResourceMap $resourceMap) {}

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return Collection<int, ProjectWorkflowRun>
     */
    public function forMappedEnvironments(array $mappedEnvironments, Workspace $workspace, int $limit): Collection
    {
        if ($mappedEnvironments === [] || ! Schema::connection('deployer')->hasTable('website_domains')) {
            return collect();
        }

        $websiteMappings = $this->resourceMap->websites($mappedEnvironments);

        if ($websiteMappings === []) {
            return collect();
        }

        return WebsiteDomain::query()
            ->whereIn('website_id', array_keys($websiteMappings))
            ->where(function ($query): void {
                $query->where('dns_status', '!=', 'active')
                    ->orWhereNull('dns_status')
                    ->orWhere('ssl_status', '!=', 'active')
                    ->orWhereNull('ssl_status');
            })
            ->orderByDesc('last_checked_at')
            ->orderByDesc('updated_at')
            ->limit(max(1, min(100, $limit)))
            ->select([
                'id',
                'website_id',
                'dns_status',
                'ssl_status',
                'last_checked_at',
                'created_at',
                'updated_at',
            ])
            ->selectRaw('CASE WHEN dns_provider_id IS NULL THEN 1 ELSE 0 END AS manual_dns_setup')
            ->get()
            ->map(function (WebsiteDomain $domain) use ($websiteMappings, $workspace): ?ProjectWorkflowRun {
                $mapping = $websiteMappings[(string) $domain->website_id] ?? null;

                if ($mapping === null) {
                    return null;
                }

                $checkedAt = $domain->last_checked_at?->toImmutable()->utc();
                $updatedAt = $domain->updated_at?->toImmutable()->utc();
                $freshness = $checkedAt ?? $updatedAt;
                $recordedAt = $freshness ?? $domain->created_at?->toImmutable()->utc() ?? CarbonImmutable::now('UTC');
                $dnsState = $this->state((string) $domain->dns_status, 'dns', (bool) $domain->manual_dns_setup, $freshness);
                $tlsState = $this->state((string) $domain->ssl_status, 'tls', false, $freshness);
                $resultUrl = Route::has('websites.show')
                    ? route('websites.show', [
                        'website' => $domain->website_id,
                        'organization_id' => $mapping['organization_id'],
                    ])
                    : null;

                return new ProjectWorkflowRun(
                    key: 'deployer:website-domain:'.$domain->getKey(),
                    title: __('Website domain status'),
                    recordedAt: $recordedAt,
                    projectId: (string) $mapping['project']->getKey(),
                    projectName: $mapping['project']->name,
                    projectUrl: route('core.projects.show', [$workspace, $mapping['project']]),
                    steps: [
                        $this->step('dns', $dnsState, $mapping, $recordedAt, $resultUrl),
                        $this->step('tls certificate', $tlsState, $mapping, $recordedAt, $resultUrl),
                    ],
                );
            })
            ->filter()
            ->values();
    }

    /** @param array{project: CoreProject, environment: Environment, label: string, organization_id: int} $mapping */
    private function step(
        string $layer,
        ProjectWorkflowStepState $state,
        array $mapping,
        CarbonImmutable $recordedAt,
        ?string $resultUrl,
    ): ProjectWorkflowStep {
        return new ProjectWorkflowStep(
            product: 'deployer',
            productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
            title: __(':environment :layer status', ['environment' => $mapping['label'], 'layer' => $layer]),
            detail: $this->detail($layer, $state),
            state: $state,
            recordedAt: $recordedAt,
            resultUrl: $resultUrl,
            environmentId: $mapping['canonical_environment_id'] ?? null,
            environmentName: $mapping['canonical_environment_name'] ?? $mapping['label'],
        );
    }

    private function state(string $status, string $layer, bool $manualDnsSetup, ?CarbonImmutable $freshness): ProjectWorkflowStepState
    {
        return match (strtolower($status)) {
            'active' => ProjectWorkflowStepState::Succeeded,
            'error', 'expired' => ProjectWorkflowStepState::Failed,
            'expiring' => ProjectWorkflowStepState::Blocked,
            'pending' => $layer === 'dns' && $manualDnsSetup
                ? ProjectWorkflowStepState::Blocked
                : ($freshness?->greaterThanOrEqualTo(CarbonImmutable::now('UTC')->subMinutes(15))
                    ? ProjectWorkflowStepState::Pending
                    : ProjectWorkflowStepState::Unknown),
            default => ProjectWorkflowStepState::Unknown,
        };
    }

    private function detail(string $layer, ProjectWorkflowStepState $state): string
    {
        if ($layer === 'dns' && $state === ProjectWorkflowStepState::Blocked) {
            return __('DNS needs setup in Deployer. Open the website for authorized details.');
        }

        if ($layer === 'tls certificate' && $state === ProjectWorkflowStepState::Blocked) {
            return __('The TLS certificate is nearing expiry. Open the website for authorized details.');
        }

        return match ($state) {
            ProjectWorkflowStepState::Succeeded => __('Deployer reports this domain layer as active.'),
            ProjectWorkflowStepState::Pending => __('This domain check is waiting for its next verification.'),
            ProjectWorkflowStepState::Failed => __('This domain layer needs attention. Open the website for authorized details.'),
            ProjectWorkflowStepState::Unknown => __('The last domain check is stale or unavailable. Open the website for current information.'),
            default => __('The domain status is unavailable. Open the website for current information.'),
        };
    }
}
