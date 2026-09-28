<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class IncidentPolicy
{
    use ManagesMonitoring;

    /**
     * Acknowledging, assigning, resolving or annotating an incident: people who manage Monitoring in the incident's project.
     *
     * @param  User  $user
     * @param  Incident  $record
     * @return bool
     */
    public function update(User $user, Incident $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->project);
    }
}
