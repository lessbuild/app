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

    public function create(User $user, Account|Project $scope): bool
    {
        return $this->allows($user, $scope instanceof Project ? $scope->account_id : $scope->id, AccountPermission::ManageSettings);
    }

    public function delete(User $user, ServerAlertRule $rule): bool
    {
        return $this->allows($user, $rule->account_id, AccountPermission::ManageSettings);
    }
}
