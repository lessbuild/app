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

    /**
     * Whether the person may manage Monitoring in the project. A missing project (a record whose project was deleted) is
     * refused.
     *
     * @param  User  $user
     * @param  Project|null  $project
     * @return bool
     */
    private function managesMonitoring(User $user, ?Project $project): bool
    {
        return $project !== null && $user->can('manageService', [$project, 'monitoring']);
    }

    /**
     * Archived records can be looked at, not changed.
     *
     * @param  Model  $record
     * @return bool
     */
    private function live(Model $record): bool
    {
        return ! in_array(SoftDeletes::class, class_uses_recursive($record), true) || $record->getAttribute('deleted_at') === null;
    }

    /**
     * Whether the person may manage the settings of the account, for Monitoring records that belong to the account
     * rather than one project (destinations, dashboards, status pages).
     *
     * @param  User  $user
     * @param  Account|Project|string  $scope
     * @return bool
     */
    private function managesAccount(User $user, Account|Project|string $scope): bool
    {
        return $this->allows($user, $this->accountIdOf($scope), AccountPermission::ManageSettings);
    }
}
