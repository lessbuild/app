<?php

declare(strict_types=1);

namespace App\Data\SiteAudits;

final readonly class SiteAuditReport
{
    /**
     * Create a new SiteAuditReport instance.
     *
     * A run's report: progress while it runs; scores, the comparison, findings and journeys once done.
     *
     * @param  SiteAuditRunSummary  $run
     * @param  array{id: int, name: string, url: string}  $audit
     * @param  string|null  $summary
     * @param  array{width: int, height: int}  $screen  the screenshots' size, for drawing boxes on them
     * @param  list<array{key: string, name: string, url: string, score: int, categories: list<array{key: string, label: string, score: int}>}>  $sites
     *                                                                                                                                                   the audited site first, then its competitors
     * @param  list<array<string, mixed>>  $findings
     * @param  list<array<string, mixed>>  $journeys
     * @param  array{journeys: int, pages: int}  $progress
     */
    public function __construct(
        public SiteAuditRunSummary $run,
        public array $audit,
        public ?string $summary,
        public array $screen,
        public array $sites,
        public array $findings,
        public array $journeys,
        public array $progress,
    ) {}
}
