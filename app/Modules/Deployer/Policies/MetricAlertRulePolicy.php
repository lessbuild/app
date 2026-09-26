<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\MetricAlertRule;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;

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
        return ($rule->server_id === null
            ? app(DeployerProjectAccess::class)->canAccessWorkspaceResources($user)
            : ($rule->server !== null && $user->can('view', $rule->server)))
            && (int) $rule->organization_id === (int) $user->current_organization_id
            && $rule->organization->permits($user, 'manage');
    }
}
