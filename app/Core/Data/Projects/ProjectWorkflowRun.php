<?php

namespace App\Core\Data\Projects;

use Carbon\CarbonImmutable;

/** @param list<ProjectWorkflowStep> $steps */
final readonly class ProjectWorkflowRun
{
    public function __construct(
        public string $key,
        public string $title,
        public CarbonImmutable $recordedAt,
        public array $steps,
    ) {}
}
