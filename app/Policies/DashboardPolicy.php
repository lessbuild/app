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
     * Creating a metrics dashboard: people who manage the account's settings.
     */
    public function create(User $user, Account|Project $scope): bool
    {
        return $this->managesAccount($user, $scope);
    }

    /**
     * Changing a dashboard: the same people.
     */
    public function update(User $user, Dashboard $record): bool
    {
        return $this->live($record) && $this->managesAccount($user, $record->account_id);
    }

    /**
     * Deleting a dashboard, allowed to the same people as update.
     */
    public function delete(User $user, Dashboard $record): bool
    {
        return $this->update($user, $record);
    }
}
