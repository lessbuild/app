<?php

namespace App\Core\Services;

use App\Core\Contracts\Deployer\WorkspaceDeployerDeploymentControlsProvider;

final class WorkspaceDeployerAdministrationProviderRegistry
{
    private ?WorkspaceDeployerDeploymentControlsProvider $deploymentControls = null;

    public function registerDeploymentControls(WorkspaceDeployerDeploymentControlsProvider $provider): void
    {
        $this->deploymentControls = $provider;
    }

    public function deploymentControls(): ?WorkspaceDeployerDeploymentControlsProvider
    {
        return $this->deploymentControls;
    }
}
