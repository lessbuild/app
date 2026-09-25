<?php

namespace App\Core\Services;

use App\Core\Contracts\Deployer\WorkspaceDeployerDeploymentControlsProvider;
use App\Core\Contracts\Deployer\WorkspaceDeployerConfigurationProvider;

final class WorkspaceDeployerAdministrationProviderRegistry
{
    private ?WorkspaceDeployerDeploymentControlsProvider $deploymentControls = null;

    private ?WorkspaceDeployerConfigurationProvider $configuration = null;

    public function registerConfiguration(WorkspaceDeployerConfigurationProvider $provider): void
    {
        $this->configuration = $provider;
    }

    public function configuration(): ?WorkspaceDeployerConfigurationProvider
    {
        return $this->configuration;
    }

    public function registerDeploymentControls(WorkspaceDeployerDeploymentControlsProvider $provider): void
    {
        $this->deploymentControls = $provider;
    }

    public function deploymentControls(): ?WorkspaceDeployerDeploymentControlsProvider
    {
        return $this->deploymentControls;
    }
}
