<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\PreviewDeployment;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;

class PreviewDeploymentPolicy
{
    /**
     * Require preview visibility in the currently selected organization.
     */
    public function view(User $user, PreviewDeployment $preview): bool
    {
        return app(DeployerProjectAccess::class)->project($user, $preview->project)
            && (int) $preview->project->organization_id === (int) $user->current_organization_id
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

    /**
     * Require workspace management permission before retrying an owned preview cleanup.
     */
    public function retryCleanup(User $user, PreviewDeployment $preview): bool
    {
        return $this->view($user, $preview)
            && $preview->project->organization->permits($user, 'manage');
    }
}
