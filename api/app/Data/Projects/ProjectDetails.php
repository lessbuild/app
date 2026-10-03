<?php

declare(strict_types=1);

namespace App\Data\Projects;

final readonly class ProjectDetails
{
    /**
     * Create a new ProjectDetails instance.
     *
     * The editable details of a project.
     *
     * @param  string  $name  The project's name.
     * @param  ?string  $description  An optional line about what the project is.
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}
}
