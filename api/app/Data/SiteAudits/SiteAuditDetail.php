<?php

declare(strict_types=1);

namespace App\Data\SiteAudits;

final readonly class SiteAuditDetail
{
    /**
     * Create a new SiteAuditDetail instance.
     *
     * One audit with its settings and runs, for its page and the edit form.
     *
     * @param  int  $id
     * @param  string  $name
     * @param  string  $url
     * @param  list<array{key: string, label: string, goal: string}>  $journeys
     * @param  string  $schedule
     * @param  string|null  $nextRunAt  ISO 8601
     * @param  list<array{id: int, name: string, url: string, source: string, reason: string|null}>  $competitors
     * @param  list<SiteAuditRunSummary>  $runs  newest first
     * @param  SiteAuditPlan  $plan
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public array $journeys,
        public string $schedule,
        public ?string $nextRunAt,
        public array $competitors,
        public array $runs,
        public SiteAuditPlan $plan,
    ) {}
}
