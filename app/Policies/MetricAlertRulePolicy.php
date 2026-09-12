<?php

namespace App\Policies;

use App\Models\MetricAlertRule;
use App\Models\User;

class MetricAlertRulePolicy
{
    /**
     * Allow a manager in the user's current workspace to create an alert rule.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->permits($user, 'manage') ?? false;
    }

    /**
     * Allow a manager to delete only a rule belonging to the selected workspace.
     */
    public function delete(User $user, MetricAlertRule $rule): bool
    {
        return (int) $rule->organization_id === (int) $user->current_organization_id
            && $rule->organization->permits($user, 'manage');
    }
}
