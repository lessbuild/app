<?php

namespace App\Core\Data\Projects;

use Carbon\CarbonImmutable;

final readonly class ProjectTrafficWindowSummary
{
    public function __construct(
        public int $pageviews,
        public int $visitors,
        public int $conversions = 0,
        public int $convertedVisits = 0,
        public ?CarbonImmutable $latestBatchProcessedAt = null,
        public ?string $sourceUrl = null,
        public int $unprocessedBatches = 0,
        public int $failedBatches = 0,
        public int $acceptedBatches = 0,
        public int $processedBatches = 0,
    ) {}
}
