<?php

namespace App\Actions\Cost;

use App\Models\Organization;
use App\Services\Entitlements;

class UpdateInfrastructureBudgetAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * Recheck the cost-control entitlement and persist the nullable monthly budget.
     *
     * @param  Organization  $organization  Current workspace whose budget is being changed.
     * @param  array<string, mixed>  $attributes  Validated budget attributes.
     */
    public function handle(Organization $organization, array $attributes): void
    {
        $this->entitlements->enforce($organization, 'cost_controls');
        $organization->update([
            'monthly_infrastructure_budget' => $attributes['monthly_infrastructure_budget'] ?? null,
        ]);
    }
}
