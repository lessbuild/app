<?php

namespace App\Core\Data\Projects;

use Carbon\CarbonImmutable;

final readonly class ProjectTrafficContextSnapshot
{
    public function __construct(
        public string $projectName,
        public string $siteName,
        public int $windowMinutes,
        public CarbonImmutable $incidentOpenedAt,
        public ProjectTrafficWindowSummary $incidentWindow,
        public ProjectTrafficWindowSummary $previousWindow,
    ) {}
}
