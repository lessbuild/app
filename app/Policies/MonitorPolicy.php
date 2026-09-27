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

    public function create(User $user, Project $project): bool
    {
        return $this->managesMonitoring($user, $project) && $user->hasVerifiedEmail();
    }

    public function update(User $user, Monitor $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->environment->project) && $user->hasVerifiedEmail();
    }

    /** Heartbeat and queue monitors have a key their jobs sign in with. */
    public function rotateKey(User $user, Monitor $record): bool
    {
        return in_array($record->type, ['heartbeat', 'queue'], true) && $this->update($user, $record);
    }

    public function delete(User $user, Monitor $record): bool
    {
        return $this->update($user, $record);
    }
}
