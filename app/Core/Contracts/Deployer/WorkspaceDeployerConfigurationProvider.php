<?php

namespace App\Core\Contracts\Deployer;

use App\Core\Data\Deployer\DeployerProjectConfigurationSnapshot;
use App\Core\Data\Deployer\DeployerProjectConfigurationDirectory;
use App\Core\Data\Deployer\DeployerEnvironmentConfigurationSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\Workspace;

interface WorkspaceDeployerConfigurationProvider
{
    public function projects(
        PlatformUser $actor,
        Workspace $workspace,
        ?string $search,
        int $page,
    ): DeployerProjectConfigurationDirectory;

    public function project(
        PlatformUser $actor,
        Workspace $workspace,
        Project $project,
        ?string $environmentSearch,
        int $environmentPage,
    ): DeployerProjectConfigurationSnapshot;

    /** @param array{preview_enabled: bool, preview_domain: ?string, preview_ttl_hours: int} $attributes */
    public function updateProjectPreviews(
        PlatformUser $actor,
        Workspace $workspace,
        Project $project,
        array $attributes,
    ): void;

    public function environment(
        PlatformUser $actor,
        Workspace $workspace,
        Project $project,
        ProjectEnvironment $environment,
    ): DeployerEnvironmentConfigurationSnapshot;

    /** @param array{name: string, type: string, branch: string, minimum_replicas: int, maximum_replicas: int, hibernate_after_minutes: ?int, post_deployment_observation_minutes: ?int} $attributes */
    public function updateEnvironment(
        PlatformUser $actor,
        Workspace $workspace,
        Project $project,
        ProjectEnvironment $environment,
        array $attributes,
    ): void;
}
