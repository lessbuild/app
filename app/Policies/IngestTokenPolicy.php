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

    public function create(User $user, Project $project): bool
    {
        return $this->managesMonitoring($user, $project);
    }

    public function update(User $user, IngestToken $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->environment->project);
    }

    public function delete(User $user, IngestToken $record): bool
    {
        return $this->update($user, $record);
    }
}
