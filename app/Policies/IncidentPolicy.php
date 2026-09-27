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

    public function create(User $user, Project $project): bool
    {
        return $this->managesMonitoring($user, $project);
    }

    public function update(User $user, Incident $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->project);
    }

    public function delete(User $user, Incident $record): bool
    {
        return $this->update($user, $record);
    }
}
