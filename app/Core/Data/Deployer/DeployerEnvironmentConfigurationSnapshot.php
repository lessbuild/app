<?php

namespace App\Core\Data\Deployer;

final readonly class DeployerEnvironmentConfigurationSnapshot
{
    public function __construct(
        public string $projectId,
        public string $projectName,
        public string $environmentId,
        public string $environmentName,
        public string $environmentType,
        public string $branch,
        public string $runtimeType,
        public ?string $runtimeVersion,
        public int $minimumReplicas,
        public int $maximumReplicas,
        public ?int $hibernateAfterMinutes,
        public ?int $postDeploymentObservationMinutes,
        // Encrypted variables stay Deployer-owned; Core only links authorized editors to the native section.
        public ?string $variablesUrl = null,
    ) {}
}
