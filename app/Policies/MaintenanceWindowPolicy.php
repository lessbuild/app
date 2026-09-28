<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class MaintenanceWindowPolicy
{
    use ManagesMonitoring;

    /**
     * Determine whether the user can schedule a maintenance window: people who manage the account's settings.
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
     * Determine whether the user can change a window: the same people.
     *
     * @param  User  $user
     * @param  MaintenanceWindow  $record
     * @return bool
     */
    public function update(User $user, MaintenanceWindow $record): bool
    {
        return $this->live($record) && $this->managesAccount($user, $record->account_id);
    }

    /**
     * Determine whether the user can delete a window, which the same people as update can.
     *
     * @param  User  $user
     * @param  MaintenanceWindow  $record
     * @return bool
     */
    public function delete(User $user, MaintenanceWindow $record): bool
    {
        return $this->update($user, $record);
    }
}
