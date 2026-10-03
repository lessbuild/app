<?php

declare(strict_types=1);

namespace App\Data\Projects;

final readonly class ServiceProjectRow
{
    /**
     * Create a new ServiceProjectRow instance.
     *
     * One project on a service's "enable in projects" list.
     *
     * @param  string  $projectId  The project's ID.
     * @param  string  $projectName  The project's name.
     * @param  bool  $enabled  Whether the service is on in that project.
     * @param  bool  $canManage  Whether the viewer may turn it on or off there.
     */
    public function __construct(
        public string $projectId,
        public string $projectName,
        public bool $enabled,
        public bool $canManage,
    ) {}
}
