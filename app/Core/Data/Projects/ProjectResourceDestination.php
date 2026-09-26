<?php

namespace App\Core\Data\Projects;

final readonly class ProjectResourceDestination
{
    public function __construct(
        public ProjectResourceDestinationState $state,
        public ?string $url = null,
    ) {}
}
