<?php

namespace App\Core\Contracts\Deployer;

use App\Core\Data\Deployer\DeploymentControlsSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\Workspace;

interface WorkspaceDeployerDeploymentControlsProvider
{
    public function snapshot(
        PlatformUser $actor,
        Workspace $workspace,
        Project $project,
        ProjectEnvironment $environment,
    ): DeploymentControlsSnapshot;

    /** @param array<string, mixed> $attributes Validated native deployment-control attributes. */
    public function update(
        PlatformUser $actor,
        Workspace $workspace,
        Project $project,
        ProjectEnvironment $environment,
        array $attributes,
    ): void;
}
