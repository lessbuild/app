<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class MonitorPolicy
{
    use ManagesMonitoring;

    /**
     * Determine whether the user can create a monitor: people who manage Monitoring in the project, with a verified
     * email since monitors send alerts.
     *
     * @param  User  $user
     * @param  Project  $project
     * @return bool
     */
    public function create(User $user, Project $project): bool
    {
        return $this->managesMonitoring($user, $project) && $user->hasVerifiedEmail();
    }

    /**
     * Determine whether the user can change, pause or resume a monitor: the same people as create, while it isn't
     * archived.
     *
     * @param  User  $user
     * @param  Monitor  $record
     * @return bool
     */
    public function update(User $user, Monitor $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->environment->project) && $user->hasVerifiedEmail();
    }

    /**
     * Determine whether the user can replace or revoke a monitor's key. Only heartbeat and queue monitors have a key
     * their jobs sign in with.
     *
     * @param  User  $user
     * @param  Monitor  $record
     * @return bool
     */
    public function rotateKey(User $user, Monitor $record): bool
    {
        return in_array($record->type, ['heartbeat', 'queue'], true) && $this->update($user, $record);
    }

    /**
     * Determine whether the user can archive a monitor, which the same people as update can.
     *
     * @param  User  $user
     * @param  Monitor  $record
     * @return bool
     */
    public function delete(User $user, Monitor $record): bool
    {
        return $this->update($user, $record);
    }
}
