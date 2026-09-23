<?php

namespace App\Core\Data\Projects;

final readonly class ProjectResourceCandidate
{
    public function __construct(
        public string $id,
        public string $resourceType,
        public string $name,
        public ?string $detail = null,
    ) {}
}
