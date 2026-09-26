<?php

namespace App\Core\Data\Projects;

final readonly class ProjectSetupStep
{
    public function __construct(
        public string $id,
        public string $product,
        public string $title,
        public string $detail,
        public ProjectSetupStepState $state,
        public ?string $url = null,
        public ?string $actionLabel = null,
        public ?string $contextName = null,
        public ?string $contextLabel = null,
    ) {}
}
