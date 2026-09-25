<?php

namespace App\Core\Data\Projects;

final readonly class ProjectInfrastructureNode
{
    public function __construct(
        public string $key,
        public string $product,
        public string $kind,
        public string $label,
        public ?string $url = null,
        public ?string $environmentId = null,
        public ?string $environmentName = null,
        public ?string $status = null,
    ) {}
}
