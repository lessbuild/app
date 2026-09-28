<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class AlertDestinationPolicy
{
    use ManagesMonitoring;

    /**
     * Adding an alert destination (email, Slack, a webhook…): people who manage the account's settings.
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
     * Changing a destination: the same people, while it isn't archived.
     *
     * @param  User  $user
     * @param  AlertDestination  $record
     * @return bool
     */
    public function update(User $user, AlertDestination $record): bool
    {
        return $this->live($record) && $this->managesAccount($user, $record->account_id);
    }

    /**
     * Archiving a destination, allowed to the same people as update.
     *
     * @param  User  $user
     * @param  AlertDestination  $record
     * @return bool
     */
    public function delete(User $user, AlertDestination $record): bool
    {
        return $this->update($user, $record);
    }
}
