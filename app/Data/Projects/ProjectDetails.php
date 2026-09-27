<?php

declare(strict_types=1);

namespace App\Data\Projects;

final readonly class ProjectDetails
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}
}
