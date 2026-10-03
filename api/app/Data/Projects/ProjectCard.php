<?php

declare(strict_types=1);

namespace App\Data\Projects;

final readonly class ProjectCard
{
    /**
     * Create a new ProjectCard instance.
     *
     * One project on the projects list.
     *
     * @param  string  $id  The project's ID.
     * @param  string  $name  The project's name.
     * @param  ?string  $description  The project's description, if it has one.
     * @param  list<string>  $serviceNames  enabled services, in registry order
     * @param  int  $environmentCount  How many environments it has.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public array $serviceNames,
        public int $environmentCount,
    ) {}
}
