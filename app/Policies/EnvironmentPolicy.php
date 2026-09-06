<?php

namespace App\Policies;

use App\Models\Environment;
use App\Models\User;

class EnvironmentPolicy
{
    public function view(User $user, Environment $environment): bool
    {
        return (int) $environment->project->organization_id === (int) $user->current_organization_id
            && $environment->project->organization->permits($user, 'view');
    }

    public function update(User $user, Environment $environment): bool
    {
        $ability = $environment->is_protected ? 'manage' : 'deploy';

        return (int) $environment->project->organization_id === (int) $user->current_organization_id
            && $environment->project->organization->permits($user, $ability);
    }

    public function delete(User $user, Environment $environment): bool
    {
        return (int) $environment->project->organization_id === (int) $user->current_organization_id
            && $environment->project->organization->permits($user, 'manage');
    }
}
