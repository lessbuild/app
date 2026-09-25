<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Models\MetricAlertRule;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use App\Modules\Deployer\Services\Entitlements;

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
        if (empty($attributes['server_id'])) {
            abort_unless(app(DeployerProjectAccess::class)->canAccessWorkspaceResources($actor), 403);
        }
        $this->entitlements->enforce($organization, 'alerts');

        return $organization->metricAlertRules()->create([
            ...$attributes,
            'created_by' => $actor->id,
            'is_enabled' => true,
        ]);
    }
}
