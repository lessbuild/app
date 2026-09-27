<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Project;
use App\Models\ServerAlertRule;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountRole;

final class ServerAlertRulePolicy
{
    use ChecksAccountRole;

    /**
     * Adding a server alert rule: people who manage the account's settings.
     */
    public function create(User $user, Account|Project $scope): bool
    {
        return $this->allows($user, $this->accountIdOf($scope), AccountPermission::ManageSettings);
    }

    /**
     * Removing a rule: the same people.
     */
    public function delete(User $user, ServerAlertRule $rule): bool
    {
        return $this->allows($user, $rule->account_id, AccountPermission::ManageSettings);
    }
}
