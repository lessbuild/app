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
     * Scheduling a maintenance window: people who manage the account's settings.
     */
    public function create(User $user, Account|Project $scope): bool
    {
        return $this->managesAccount($user, $scope);
    }

    /**
     * Changing a window: the same people.
     */
    public function update(User $user, MaintenanceWindow $record): bool
    {
        return $this->live($record) && $this->managesAccount($user, $record->account_id);
    }

    /**
     * Deleting a window, allowed to the same people as update.
     */
    public function delete(User $user, MaintenanceWindow $record): bool
    {
        return $this->update($user, $record);
    }
}
