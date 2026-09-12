<?php

namespace App\Policies;

use App\Models\ConfigurationApplication;
use App\Models\User;

class ConfigurationApplicationPolicy
{
    /**
     * Allow workspace managers to inspect a configuration receipt after its parent lookup is verified.
     */
    public function view(User $user, ConfigurationApplication $application): bool
    {
        $project = $application->review?->project;

        return $project !== null
            && (int) $project->organization_id === (int) $user->current_organization_id
            && $project->organization->permits($user, 'manage');
    }

    /**
     * Allow any current workspace manager to cancel a related pending operation.
     */
    public function cancel(User $user, ConfigurationApplication $application): bool
    {
        return $this->view($user, $application);
    }

    /**
     * Allow only the original review author to retry a related operation.
     */
    public function retry(User $user, ConfigurationApplication $application): bool
    {
        return $this->view($user, $application)
            && (int) $application->review->requested_by === (int) $user->id;
    }
}
