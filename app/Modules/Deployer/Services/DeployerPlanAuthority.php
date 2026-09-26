<?php

namespace App\Modules\Deployer\Services;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Enums\ProductKey;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\ProductSubscription;
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
        $workspaceId = $this->workspaceId($organization);

        if ($workspaceId === null) {
            return ProductPlanResolution::unavailable(
                ProductKey::Deployer,
                null,
                'workspace_mapping_missing',
            );
        }

        return $this->plans->resolve($workspaceId, ProductKey::Deployer);
    }

    public function workspaceId(Organization $organization): ?string
    {
        $workspaceId = $this->identities->canonicalIdForSource(
            product: ProductKey::Deployer->value,
            sourceEntity: 'organization',
            sourceId: $organization->getKey(),
            canonicalEntity: 'workspace',
        );

        return is_string($workspaceId) && $workspaceId !== '' ? $workspaceId : null;
    }

    public function currentSubscription(Organization $organization): ?ProductSubscription
    {
        $workspaceId = $this->workspaceId($organization);
        if ($workspaceId === null) {
            return null;
        }

        $subscription = CurrentProductSubscription::query()
            ->with('subscription.billingCustomer')
            ->where('workspace_id', $workspaceId)
            ->where('product', ProductKey::Deployer->value)
            ->first()?->subscription;

        return $subscription instanceof ProductSubscription
            && (string) $subscription->workspace_id === $workspaceId
            && $subscription->product === ProductKey::Deployer->value
            ? $subscription
            : null;
    }
}
