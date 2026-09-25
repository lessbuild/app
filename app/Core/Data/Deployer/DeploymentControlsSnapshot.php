<?php

namespace App\Core\Data\Deployer;

final readonly class DeploymentControlsSnapshot
{
    /**
     * @param  list<int>  $windowDays
     */
    public function __construct(
        public string $projectId,
        public string $projectName,
        public string $environmentId,
        public string $environmentName,
        public string $environmentType,
        public bool $deploymentLocked,
        public ?string $deploymentLockReason,
        public bool $deploymentWindowEnabled,
        public array $deploymentWindowDays,
        public ?string $deploymentWindowStart,
        public ?string $deploymentWindowEnd,
        public ?string $deploymentWindowTimezone,
        public string $deploymentStrategy,
        public int $rollingPauseSeconds,
        public bool $automaticRollback,
    ) {}
}
