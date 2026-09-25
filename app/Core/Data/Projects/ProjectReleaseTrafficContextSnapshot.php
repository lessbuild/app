<?php

namespace App\Core\Data\Projects;

use Carbon\CarbonImmutable;

final readonly class ProjectReleaseTrafficContextSnapshot
{
    public function __construct(
        public string $projectName,
        public string $siteName,
        public int $windowSeconds,
        public CarbonImmutable $deployedAt,
        public ProjectTrafficWindowSummary $before,
        public ProjectTrafficWindowSummary $after,
    ) {}
}
