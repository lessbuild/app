<?php

namespace App\Core\Data\Deployer;

final readonly class DeployerProjectConfigurationSnapshot
{
    /**
     * @param  list<DeployerEnvironmentConfigurationSnapshot>  $environments
     */
    public function __construct(
        public string $projectId,
        public string $projectName,
        public bool $previewEnabled,
        public ?string $previewDomain,
        public int $previewTtlHours,
        public array $environments,
        public int $environmentPage = 1,
        public bool $hasPreviousEnvironmentPage = false,
        public bool $hasNextEnvironmentPage = false,
        public ?string $environmentSearch = null,
    ) {}
}
