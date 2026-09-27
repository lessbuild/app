<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Models\Environment;
use App\Models\Project;

final readonly class ProjectOverview
{
    /**
     * Everything the project overview page shows.
     *
     * @param  Project  $project  The project.
     * @param  list<Environment>  $environments  production first
     * @param  list<ServiceCard>  $services  every registered service, in registry order
     * @param  bool  $canManage  Whether the viewer may change the project and its services.
     */
    public function __construct(
        public Project $project,
        public array $environments,
        public array $services,
        public bool $canManage,
    ) {}

    /**
     * The services turned on in this project, in registry order.
     *
     * @return list<ServiceCard>
     */
    public function enabledServices(): array
    {
        return array_values(array_filter($this->services, fn (ServiceCard $service): bool => $service->enabled));
    }
}
