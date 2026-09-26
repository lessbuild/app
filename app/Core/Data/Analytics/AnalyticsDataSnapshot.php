<?php

namespace App\Core\Data\Analytics;

final readonly class AnalyticsDataSnapshot
{
    /** @param list<AnalyticsReportSummary> $reports @param list<AnalyticsProcessingSummary> $processing */
    public function __construct(
        public array $reports,
        public array $processing,
        public bool $available = true,
        public bool $canExportWorkspace = false,
    ) {}
}
