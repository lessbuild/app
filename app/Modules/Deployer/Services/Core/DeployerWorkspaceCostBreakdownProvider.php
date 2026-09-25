<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\WorkspaceCostBreakdownProvider;
use App\Core\Data\Costs\WorkspaceCostBreakdown;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Actions\Cost\UpdateInfrastructureBudgetAction;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Entitlements;
use App\Modules\Deployer\Services\InfrastructureCostQuery;
use App\Modules\Deployer\Services\PreviewUsageQuery;

final class DeployerWorkspaceCostBreakdownProvider implements WorkspaceCostBreakdownProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $workspaceAccess,
        private readonly Entitlements $entitlements,
        private readonly InfrastructureCostQuery $costs,
        private readonly PreviewUsageQuery $previewUsage,
        private readonly UpdateInfrastructureBudgetAction $updateBudget,
    ) {}

    public function summarize(PlatformUser $user, Workspace $workspace): ?WorkspaceCostBreakdown
    {
        $context = $this->context($user, $workspace);
        if ($context === null) {
            return null;
        }

        [$organization, $productUser] = $context;
        $report = $this->costs->for($organization);
        $previews = $this->previewUsage->for($organization);
        $rows = $report->rows->map(fn ($row): array => [
            'label' => (string) $row->server->label,
            'provider' => $row->server->provider?->name,
            'size' => $row->server->size,
            'websites' => (int) $row->server->websites_count,
            'average_cpu' => $row->averageCpu,
            'monthly' => $row->monthly,
            'idle' => $row->idle,
            'attribution' => $row->attribution,
            'project_names' => $row->projectNames,
            'catalog_observed_at' => $row->catalogObservedAt?->toDayDateTimeString(),
        ])->values()->all();
        $previewRows = $previews->previews->map(fn ($lifetime): array => [
            'project_name' => (string) $lifetime->project->name,
            'pull_request_number' => $lifetime->preview->pull_request_number === null ? null : (int) $lifetime->preview->pull_request_number,
            'status' => (string) $lifetime->preview->status,
            'ttl_hours' => (int) $lifetime->ttlHours,
            'expired' => (bool) $lifetime->expired,
            'expires_at' => $lifetime->expiresAt?->toDayDateTimeString(),
        ])->values()->all();

        return new WorkspaceCostBreakdown(
            rows: $rows,
            estimated: $report->estimated,
            unknownCount: $report->unknownCount,
            idleCount: $report->idleCount,
            previewUsage: [
                'limit' => $previews->limit,
                'used' => $previews->used,
                'previews' => $previewRows,
                'hidden_count' => $previews->hiddenCount,
            ],
            budget: $organization->monthly_infrastructure_budget === null ? null : (float) $organization->monthly_infrastructure_budget,
            canManage: $organization->permits($productUser, 'manage') && $this->entitlements->allows($organization, 'cost_controls'),
            featureAvailable: $this->entitlements->allows($organization, 'cost_controls'),
        );
    }

    public function updateMonthlyBudget(PlatformUser $user, Workspace $workspace, ?float $amount): bool
    {
        $context = $this->context($user, $workspace);
        if ($context === null) {
            return false;
        }

        [$organization, $productUser] = $context;
        if (! $organization->permits($productUser, 'manage')) {
            return false;
        }

        $this->updateBudget->handle($organization, ['monthly_infrastructure_budget' => $amount]);

        return true;
    }

    /** @return array{Organization, User}|null */
    private function context(PlatformUser $user, Workspace $workspace): ?array
    {
        $organizationIds = LegacyIdentityMap::query()
            ->where('source_product', 'deployer')
            ->where('source_entity', 'organization')
            ->where('canonical_entity', 'workspace')
            ->where('canonical_id', (string) $workspace->getKey())
            ->where('status', 'reconciled')
            ->pluck('source_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values();
        $userIds = $this->identities->sourceIdsFor($user, 'deployer');

        if ($organizationIds->count() !== 1 || count($userIds) !== 1) {
            return null;
        }

        $organization = Organization::query()->find($organizationIds->sole());
        $productUser = User::query()->find($userIds[0]);

        $membership = $this->workspaceAccess->activeMembership($user, $workspace);

        if (! $organization instanceof Organization
            || ! $productUser instanceof User
            || ! $organization->permits($productUser, 'view')
            || $membership === null
            || ! $this->workspaceAccess->hasProductAccess($membership, 'deployer')) {
            return null;
        }

        return [$organization, $productUser];
    }
}
