<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IngestToken;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class IngestTokenPolicy
{
    use ManagesMonitoring;

    /**
     * Determine whether the user can create a telemetry ingest token: people who manage Monitoring in the project.
     *
     * @param  User  $user
     * @param  Project  $project
     * @return bool
     */
    public function create(User $user, Project $project): bool
    {
        return $this->managesMonitoring($user, $project);
    }

    /**
     * Determine whether the user can change a token: the same people.
     *
     * @param  User  $user
     * @param  IngestToken  $record
     * @return bool
     */
    public function update(User $user, IngestToken $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->environment->project);
    }

    /**
     * Determine whether the user can revoke a token, which the same people as update can.
     *
     * @param  User  $user
     * @param  IngestToken  $record
     * @return bool
     */
    public function delete(User $user, IngestToken $record): bool
    {
        return $this->update($user, $record);
    }
}
