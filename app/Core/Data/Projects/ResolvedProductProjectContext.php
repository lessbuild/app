<?php

namespace App\Core\Data\Projects;

use App\Core\Models\Project;

final readonly class ResolvedProductProjectContext
{
    /** @param array<string, string> $productUrlOverrides */
    public function __construct(
        public ProductProjectContextState $state,
        public ?Project $project = null,
        public ?ProjectEnvironmentContext $environment = null,
        public array $productUrlOverrides = [],
    ) {}

    public function isAvailable(): bool
    {
        return $this->state === ProductProjectContextState::Available;
    }

    public function isUnavailable(): bool
    {
        return $this->state === ProductProjectContextState::Unavailable;
    }
}
