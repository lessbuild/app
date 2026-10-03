<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class DashboardPolicy
{
    use ManagesMonitoring;

    /**
     * Determine whether the user can create a metrics dashboard: people who manage the account's settings.
     *
     * @param  User  $user
     * @param  Account|Project  $scope
     * @return bool
     */
    public function create(User $user, Account|Project $scope): bool
    {
        return $this->managesAccount($user, $scope);
    }

    /**
     * Determine whether the user can change a dashboard: the same people.
     *
     * @param  User  $user
     * @param  Dashboard  $record
     * @return bool
     */
    public function update(User $user, Dashboard $record): bool
    {
        return $this->live($record) && $this->managesAccount($user, $record->account_id);
    }

    /**
     * Determine whether the user can delete a dashboard, which the same people as update can.
     *
     * @param  User  $user
     * @param  Dashboard  $record
     * @return bool
     */
    public function delete(User $user, Dashboard $record): bool
    {
        return $this->update($user, $record);
    }
}
