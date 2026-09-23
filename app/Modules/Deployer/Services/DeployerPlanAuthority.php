<?php

namespace App\Modules\Deployer\Services;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Enums\ProductKey;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Models\Organization;

final readonly class DeployerPlanAuthority
{
    public function __construct(
        private LegacyIdentityResolver $identities,
        private ProductPlanResolver $plans,
    ) {}

    /** Any value other than the explicit legacy mode uses Core and fails closed. */
    public function usesCore(): bool
    {
        return config('billing.plan_authority', 'legacy') !== 'legacy';
    }

    public function resolve(Organization $organization): ProductPlanResolution
    {
        $workspaceId = $this->identities->canonicalIdForSource(
            product: ProductKey::Deployer->value,
            sourceEntity: 'organization',
            sourceId: $organization->getKey(),
            canonicalEntity: 'workspace',
        );

        if (! is_string($workspaceId) || $workspaceId === '') {
            return ProductPlanResolution::unavailable(
                ProductKey::Deployer,
                null,
                'workspace_mapping_missing',
            );
        }

        return $this->plans->resolve($workspaceId, ProductKey::Deployer);
    }
}
