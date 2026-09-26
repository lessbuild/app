<?php

declare(strict_types=1);

namespace App\Domain\Projects\Data;

use App\Domain\Projects\Models\Environment;
use App\Domain\Projects\Models\Project;

final readonly class ProjectOverview
{
    /**
     * @param  list<Environment>  $environments  production first
     * @param  list<ServiceCard>  $services  every registered service, in registry order
     */
    public function __construct(
        public Project $project,
        public array $environments,
        public array $services,
        public bool $canManage,
    ) {}

    /** @return list<ServiceCard> */
    public function enabledServices(): array
    {
        return array_values(array_filter($this->services, fn (ServiceCard $service): bool => $service->enabled));
    }
}
