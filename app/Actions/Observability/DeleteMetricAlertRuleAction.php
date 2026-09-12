<?php

namespace App\Actions\Observability;

use App\Models\MetricAlertRule;
use App\Services\Entitlements;

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
