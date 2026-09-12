<?php

namespace App\Actions\Observability;

use App\Models\MetricAlertRule;
use App\Models\Organization;
use App\Models\User;
use App\Services\Entitlements;

class CreateMetricAlertRuleAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Persist an enabled metric alert rule for an entitled workspace after request authorization.
     *
     * @param  Organization  $organization  Workspace that owns the rule.
     * @param  User  $actor  Account recorded as the rule creator.
     * @param  array<string, mixed>  $attributes  Validated metric rule fields.
     */
    public function handle(Organization $organization, User $actor, array $attributes): MetricAlertRule
    {
        $this->entitlements->enforce($organization, 'alerts');

        return $organization->metricAlertRules()->create([
            ...$attributes,
            'created_by' => $actor->id,
            'is_enabled' => true,
        ]);
    }
}
