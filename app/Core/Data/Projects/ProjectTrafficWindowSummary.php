<?php

namespace App\Core\Data\Projects;

use Carbon\CarbonImmutable;

final readonly class ProjectTrafficWindowSummary
{
    public function __construct(
        public int $pageviews,
        public int $visitors,
        public ?CarbonImmutable $processedAt = null,
    ) {}
}
