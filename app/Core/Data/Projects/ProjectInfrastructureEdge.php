<?php

namespace App\Core\Data\Projects;

final readonly class ProjectInfrastructureEdge
{
    public function __construct(
        public string $sourceKey,
        public string $targetKey,
        public string $label,
    ) {}
}
