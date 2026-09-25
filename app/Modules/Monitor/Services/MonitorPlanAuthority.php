<?php

namespace App\Modules\Monitor\Services;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Enums\ProductKey;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\ProductSubscription;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;

final readonly class MonitorPlanAuthority
{
    public function __construct(
        private LegacyIdentityResolver $identities,
        private ProductPlanResolver $plans,
    ) {}

    /** Any non-legacy value opts into Core and fails closed. */
    public function usesCore(): bool
    {
        return config('monitor.beacon.plan_authority', 'legacy') !== 'legacy';
    }

    public function resolve(Workspace $workspace): ProductPlanResolution
    {
        try {
            $workspaceId = $this->identities->canonicalIdForSource(
                product: ProductKey::Monitor->value,
                sourceEntity: 'workspace',
                sourceId: $workspace->getKey(),
                canonicalEntity: 'workspace',
            );

            if (! is_string($workspaceId) || $workspaceId === '') {
                return ProductPlanResolution::unavailable(
                    ProductKey::Monitor,
                    null,
                    'workspace_mapping_missing',
                );
            }

            return $this->plans->resolve($workspaceId, ProductKey::Monitor);
        } catch (LostConnectionException|QueryException) {
            return ProductPlanResolution::unavailable(
                ProductKey::Monitor,
                null,
                'plan_authority_unavailable',
            );
        }
    }

    public function stripeSubscriptionId(Workspace $workspace): ?string
    {
        try {
            $workspaceId = $this->identities->canonicalIdForSource(
                product: ProductKey::Monitor->value,
                sourceEntity: 'workspace',
                sourceId: $workspace->getKey(),
                canonicalEntity: 'workspace',
            );

            if (! is_string($workspaceId) || $workspaceId === '') {
                return null;
            }

            $subscription = CurrentProductSubscription::query()
                ->with('subscription')
                ->where('workspace_id', $workspaceId)
                ->where('product', ProductKey::Monitor->value)
                ->first()?->subscription;

            if (! $subscription instanceof ProductSubscription
                || $subscription->provider !== 'stripe'
                || $subscription->provider_account_key !== 'monitor') {
                return null;
            }

            $subscriptionId = $subscription->provider_subscription_id;

            return is_string($subscriptionId) && $subscriptionId !== '' ? $subscriptionId : null;
        } catch (LostConnectionException|QueryException) {
            return null;
        }
    }
}
