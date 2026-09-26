<?php

namespace App\Core\Data\Analytics;

final readonly class WorkspaceAnalyticsSiteSnapshot
{
    /** @param list<AnalyticsSiteSummary> $sites */
    public function __construct(
        public array $sites,
        public bool $available = true,
        public bool $canManageSites = false,
        public bool $planAvailable = false,
        public ?string $planName = null,
        public ?int $siteLimit = null,
        public int $siteCount = 0,
        public ?int $detailRetentionDays = null,
        public ?int $aggregateRetentionMonths = null,
        public ?int $exportRetentionHours = null,
        public bool $canCreateSite = false,
        public bool $canRequestReport = false,
        public bool $truncated = false,
    ) {}
}
