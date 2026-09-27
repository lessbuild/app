<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;

/** The two Monitoring rules: project records need Monitoring management on the project; account records need account settings access. */
trait ManagesMonitoring
{
    use ChecksAccountRole;

    private function managesMonitoring(User $user, ?Project $project): bool
    {
        return $project !== null && $user->can('manageService', [$project, 'monitoring']);
    }

    private function managesAccount(User $user, Account|Project|string $scope): bool
    {
        $accountId = match (true) {
            $scope instanceof Project => $scope->account_id,
            $scope instanceof Account => $scope->id,
            default => $scope,
        };

        return $this->allows($user, $accountId, AccountPermission::ManageSettings);
    }
}
