<?php

namespace App\Core\Data\Projects;

use App\Core\Models\ProjectEnvironment;

final readonly class ProjectEnvironmentContext
{
    public function __construct(
        public ProjectEnvironmentContextState $state,
        public ?ProjectEnvironment $environment = null,
        public ?string $requestedId = null,
    ) {}

    public function isSelected(): bool
    {
        return $this->state === ProjectEnvironmentContextState::Selected && $this->environment !== null;
    }

    public function wasRequested(): bool
    {
        return $this->state !== ProjectEnvironmentContextState::All;
    }

    public function isUnavailable(): bool
    {
        return $this->state === ProjectEnvironmentContextState::Unavailable;
    }

    public function name(): ?string
    {
        return $this->environment?->name;
    }
}
