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
     * Creating a monitor: people who manage Monitoring in the project, with a verified email since monitors send alerts.
     */
    public function create(User $user, Project $project): bool
    {
        return $this->managesMonitoring($user, $project) && $user->hasVerifiedEmail();
    }

    /**
     * Changing, pausing or resuming a monitor: the same people as create, while it isn't archived.
     */
    public function update(User $user, Monitor $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->environment->project) && $user->hasVerifiedEmail();
    }

    /** Heartbeat and queue monitors have a key their jobs sign in with. */
    public function rotateKey(User $user, Monitor $record): bool
    {
        return in_array($record->type, ['heartbeat', 'queue'], true) && $this->update($user, $record);
    }

    /**
     * Archiving a monitor, allowed to the same people as update.
     */
    public function delete(User $user, Monitor $record): bool
    {
        return $this->update($user, $record);
    }
}
