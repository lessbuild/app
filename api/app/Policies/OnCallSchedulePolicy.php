<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\OnCallSchedule;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class OnCallSchedulePolicy
{
    use ManagesMonitoring;

    /**
     * Determine whether the person may add on-call schedules: people who manage the account's settings, like alert
     * destinations.
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
     * Determine whether the person may change the schedule, its members and its overrides.
     *
     * @param  User  $user
     * @param  OnCallSchedule  $schedule
     * @return bool
     */
    public function update(User $user, OnCallSchedule $schedule): bool
    {
        return $this->managesAccount($user, $schedule->account_id);
    }

    /**
     * Determine whether the person may delete the schedule.
     *
     * @param  User  $user
     * @param  OnCallSchedule  $schedule
     * @return bool
     */
    public function delete(User $user, OnCallSchedule $schedule): bool
    {
        return $this->update($user, $schedule);
    }
}
