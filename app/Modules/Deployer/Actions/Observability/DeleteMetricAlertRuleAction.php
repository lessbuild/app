<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Models\MetricAlertRule;
use App\Modules\Deployer\Services\Entitlements;

class DeleteMetricAlertRuleAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Delete an authorized metric alert rule while retaining the paid-alert entitlement check.
     */
    public function handle(MetricAlertRule $rule): void
    {
        $this->entitlements->enforce($rule->organization, 'alerts');
        $rule->delete();
    }
}
