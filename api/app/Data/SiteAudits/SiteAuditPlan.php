<?php

declare(strict_types=1);

namespace App\Data\SiteAudits;

final readonly class SiteAuditPlan
{
    /**
     * Create a new SiteAuditPlan instance.
     *
     * What the account's Audit plan allows, and what the person may do.
     *
     * @param  string|null  $tier  the tier's name, or null without a plan
     * @param  int  $runsUsed  audits run this month
     * @param  int|null  $runsAllowance  null for unlimited
     * @param  int|null  $competitorLimit  null for unlimited
     * @param  int|null  $auditLimit  sites the account may audit; null for unlimited
     * @param  list<string>  $schedules  the schedules the plan includes
     * @param  bool  $canManage  whether the person may create, change and run audits
     */
    public function __construct(
        public ?string $tier,
        public int $runsUsed,
        public ?int $runsAllowance,
        public ?int $competitorLimit,
        public ?int $auditLimit,
        public array $schedules,
        public bool $canManage,
    ) {}
}
