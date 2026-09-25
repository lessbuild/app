<?php

namespace App\Core\Data\Analytics;

use Carbon\CarbonImmutable;

final readonly class AnalyticsReportSummary
{
    /** @param array<string, mixed> $filters */
    public function __construct(
        public string $id,
        public string $siteId,
        public string $siteName,
        public string $status,
        public array $filters,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $expiresAt,
        public bool $downloadAvailable,
        public bool $canRetry,
    ) {}
}
