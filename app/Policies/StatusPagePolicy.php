<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class StatusPagePolicy
{
    use ManagesMonitoring;

    /**
     * Creating a status page: people who manage the account's settings.
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
     * Changing a status page or posting an update: the same people.
     *
     * @param  User  $user
     * @param  StatusPage  $record
     * @return bool
     */
    public function update(User $user, StatusPage $record): bool
    {
        return $this->live($record) && $this->managesAccount($user, $record->account_id);
    }

    /**
     * Deleting a status page, allowed to the same people as update.
     *
     * @param  User  $user
     * @param  StatusPage  $record
     * @return bool
     */
    public function delete(User $user, StatusPage $record): bool
    {
        return $this->update($user, $record);
    }
}
