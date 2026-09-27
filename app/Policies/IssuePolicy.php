<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ManagesMonitoring;

final class IssuePolicy
{
    use ManagesMonitoring;

    /**
     * Resolving, snoozing, ignoring or assigning an issue: people who manage Monitoring in the issue's project.
     */
    public function update(User $user, Issue $record): bool
    {
        return $this->live($record) && $this->managesMonitoring($user, $record->project);
    }
}
