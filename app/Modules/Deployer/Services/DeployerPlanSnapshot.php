<?php

namespace App\Modules\Deployer\Services;

final class DeployerPlanSnapshot
{
    /** @return array<string, mixed>|null */
    public function forPlan(string $planKey): ?array
    {
        $plan = config('billing.plans.'.$planKey);

        if (! is_array($plan)
            || ! is_array($plan['entitlements'] ?? null)
            || ! is_array($plan['limits'] ?? null)) {
            return null;
        }

        return $plan;
    }
}
