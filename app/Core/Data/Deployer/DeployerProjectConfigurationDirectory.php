<?php

namespace App\Core\Data\Deployer;

final readonly class DeployerProjectConfigurationDirectory
{
    /**
     * @param  list<DeployerProjectConfigurationSnapshot>  $projects
     */
    public function __construct(
        public array $projects,
        public int $page,
        public bool $hasPreviousPage,
        public bool $hasNextPage,
        public ?string $search,
    ) {}
}
