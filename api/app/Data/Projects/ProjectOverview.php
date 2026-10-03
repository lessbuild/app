<?php

declare(strict_types=1);

namespace App\Data\Projects;

final readonly class ProjectOverview
{
    /**
     * Create a new ProjectOverview instance.
     *
     * Everything the project overview page shows.
     *
     * @param  ProjectSummary  $project  The project.
     * @param  list<EnvironmentSummary>  $environments  production first
     * @param  list<ServiceCard>  $services  every registered service, in registry order
     * @param  bool  $canManage  Whether the viewer may change the project and its services.
     */
    public function __construct(
        public ProjectSummary $project,
        public array $environments,
        public array $services,
        public bool $canManage,
    ) {}

    /**
     * Get the services turned on in this project, in registry order.
     *
     * @return list<ServiceCard>
     */
    public function enabledServices(): array
    {
        return array_values(array_filter($this->services, fn (ServiceCard $service): bool => $service->enabled));
    }
}
