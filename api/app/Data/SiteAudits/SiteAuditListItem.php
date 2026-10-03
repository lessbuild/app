<?php

declare(strict_types=1);

namespace App\Data\SiteAudits;

final readonly class SiteAuditListItem
{
    /**
     * Create a new SiteAuditListItem instance.
     *
     * An audit on the project's Audit page.
     *
     * @param  int  $id
     * @param  string  $name
     * @param  string  $url
     * @param  string  $schedule
     * @param  int  $competitors  how many competitors it's compared with
     * @param  SiteAuditRunSummary|null  $latestRun
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public string $schedule,
        public int $competitors,
        public ?SiteAuditRunSummary $latestRun,
    ) {}
}
