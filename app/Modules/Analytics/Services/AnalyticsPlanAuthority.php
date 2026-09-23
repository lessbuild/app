<?php

namespace App\Modules\Analytics\Services;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Enums\ProductKey;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;

final readonly class AnalyticsPlanAuthority
{
    public function __construct(
        private LegacyIdentityResolver $identities,
        private ProductPlanResolver $plans,
    ) {}

    /** Any non-legacy value opts into Core and fails closed. */
    public function usesCore(): bool
    {
        return config('analytics.plan_authority', 'legacy') !== 'legacy';
    }

    public function resolve(Workspace $workspace): ProductPlanResolution
    {
        if (! $this->usesCore()) {
            return $this->legacyResolution((string) $workspace->getKey());
        }

        try {
            $workspaceId = $this->identities->canonicalIdForSource(
                product: ProductKey::Analytics->value,
                sourceEntity: 'workspace',
                sourceId: $workspace->getKey(),
                canonicalEntity: 'workspace',
            );

            if (! is_string($workspaceId) || $workspaceId === '') {
                return ProductPlanResolution::unavailable(
                    ProductKey::Analytics,
                    null,
                    'workspace_mapping_missing',
                );
            }

            return $this->plans->resolve($workspaceId, ProductKey::Analytics);
        } catch (LostConnectionException|QueryException) {
            return ProductPlanResolution::unavailable(
                ProductKey::Analytics,
                null,
                'plan_authority_unavailable',
            );
        }
    }

    public function canCollect(Workspace $workspace): bool
    {
        if (! $this->usesCore()) {
            return true;
        }

        return $this->resolve($workspace)->allows('event_collection');
    }

    private function legacyResolution(string $workspaceId): ProductPlanResolution
    {
        $snapshot = [
            'name' => 'Existing Analytics access',
            'entitlements' => ['*'],
            'limits' => [
                'sites' => null,
                'members' => null,
                'events_per_month' => null,
                'retention_days' => max(1, (int) config('analytics.event_retention_days', 90)),
                'aggregate_retention_months' => max(1, (int) config('analytics.aggregate_retention_months', 13)),
                'export_retention_hours' => max(1, (int) config('analytics.export_retention_hours', 24)),
            ],
        ];

        return new ProductPlanResolution(
            product: ProductKey::Analytics,
            workspaceId: $workspaceId,
            available: true,
            planKey: 'legacy',
            planName: $snapshot['name'],
            subscriptionStatus: 'active',
            entitlements: $snapshot['entitlements'],
            limits: $snapshot['limits'],
            snapshot: $snapshot,
        );
    }
}
