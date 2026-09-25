<?php

namespace App\Core\Data\Analytics;

use Carbon\CarbonImmutable;

final readonly class AnalyticsProcessingSummary
{
    public function __construct(
        public string $siteId,
        public string $siteName,
        public string $status,
        public int $acceptedEventCount,
        public CarbonImmutable $acceptedAt,
        public ?CarbonImmutable $processedAt,
    ) {}
}
