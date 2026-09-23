<?php

namespace App\Core\Services\Billing;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Enums\ProductKey;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\ProductSubscription;
use Illuminate\Support\Carbon;

final class ResolveProductPlan implements ProductPlanResolver
{
    public function resolve(string $workspaceId, ProductKey $product): ProductPlanResolution
    {
        $current = CurrentProductSubscription::query()
            ->with('subscription')
            ->where('workspace_id', $workspaceId)
            ->where('product', $product->value)
            ->first();

        if (! $current instanceof CurrentProductSubscription) {
            return ProductPlanResolution::unavailable($product, $workspaceId, 'current_subscription_missing');
        }

        $subscription = $current->subscription;

        if (! $subscription instanceof ProductSubscription
            || (string) $subscription->workspace_id !== $workspaceId
            || $subscription->product !== $product->value) {
            return ProductPlanResolution::unavailable($product, $workspaceId, 'current_subscription_mapping_invalid');
        }

        $allowedStatuses = config('platform.billing.entitled_statuses.'.$product->value, ['active', 'trialing']);
        $status = $subscription->status;

        if (! is_array($allowedStatuses) || ! in_array($status, $allowedStatuses, true)) {
            return ProductPlanResolution::unavailable($product, $workspaceId, 'subscription_status_not_entitled', $status);
        }

        if ($subscription->current_period_ends_at !== null
            && Carbon::parse($subscription->current_period_ends_at)->isPast()) {
            return ProductPlanResolution::unavailable($product, $workspaceId, 'subscription_period_expired', $status);
        }

        $metadata = $subscription->metadata;
        $snapshot = is_array($metadata) && is_array($metadata['plan_snapshot'] ?? null)
            ? $metadata['plan_snapshot']
            : null;
        $planKey = is_string($subscription->plan_key) && $subscription->plan_key !== ''
            ? $subscription->plan_key
            : null;
        $entitlements = $snapshot['entitlements'] ?? null;
        $limits = $snapshot['limits'] ?? null;

        if ($snapshot === null
            || $planKey === null
            || ! is_array($entitlements)
            || ! is_array($limits)
            || ! $this->isStringList($entitlements)
            || ! $this->isLimitMap($limits)) {
            return ProductPlanResolution::unavailable($product, $workspaceId, 'plan_snapshot_missing_or_invalid', $status);
        }

        return new ProductPlanResolution(
            product: $product,
            workspaceId: $workspaceId,
            available: true,
            planKey: $planKey,
            planName: is_string($snapshot['name'] ?? null) ? $snapshot['name'] : $planKey,
            subscriptionStatus: $status,
            entitlements: array_values($entitlements),
            limits: $limits,
            snapshot: $snapshot,
        );
    }

    /** @param array<mixed> $values */
    private function isStringList(array $values): bool
    {
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                return false;
            }
        }

        return array_is_list($values);
    }

    /** @param array<mixed> $limits */
    private function isLimitMap(array $limits): bool
    {
        foreach ($limits as $resource => $limit) {
            if (! is_string($resource) || $resource === '' || (! is_int($limit) && $limit !== null) || (is_int($limit) && $limit < 0)) {
                return false;
            }
        }

        return true;
    }
}
