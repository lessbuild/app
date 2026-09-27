<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The two Monitoring rules: project records need Monitoring management on the project; account records need account settings access. */
trait ManagesMonitoring
{
    use ChecksAccountRole;

    private function managesMonitoring(User $user, ?Project $project): bool
    {
        return $project !== null && $user->can('manageService', [$project, 'monitoring']);
    }

    /** Archived records can be looked at, not changed. */
    private function live(Model $record): bool
    {
        return ! in_array(SoftDeletes::class, class_uses_recursive($record), true) || $record->getAttribute('deleted_at') === null;
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
