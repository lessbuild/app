<?php

namespace App\Policies;

use App\Models\PreviewDeployment;
use App\Models\User;

class PreviewDeploymentPolicy
{
    /**
     * Require preview visibility in the currently selected organization.
     */
    public function view(User $user, PreviewDeployment $preview): bool
    {
        return (int) $preview->project->organization_id === (int) $user->current_organization_id
            && $preview->project->organization->permits($user, 'view');
    }

    /**
     * Require workspace management permission before allowing source secrets into a preview scope.
     */
    public function approveSecrets(User $user, PreviewDeployment $preview): bool
    {
        return $this->view($user, $preview)
            && $preview->project->organization->permits($user, 'manage');
    }
}
