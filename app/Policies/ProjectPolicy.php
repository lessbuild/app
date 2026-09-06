<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return (int) $project->organization_id === (int) $user->current_organization_id
            && $project->organization->permits($user, 'view');
    }

    public function update(User $user, Project $project): bool
    {
        return (int) $project->organization_id === (int) $user->current_organization_id
            && $project->organization->permits($user, 'deploy');
    }

    public function delete(User $user, Project $project): bool
    {
        return (int) $project->organization_id === (int) $user->current_organization_id
            && $project->organization->permits($user, 'manage');
    }
}
