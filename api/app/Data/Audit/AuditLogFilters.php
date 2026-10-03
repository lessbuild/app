<?php

declare(strict_types=1);

namespace App\Data\Audit;

use Carbon\CarbonImmutable;

/** What the audit log is narrowed to. Every field is optional; checked against the account before it gets here. */
final readonly class AuditLogFilters
{
    /**
     * Create a new AuditLogFilters instance.
     *
     * @param  string|null  $projectId  One of the account's projects.
     * @param  string|null  $actorId  One of the account's members.
     * @param  string|null  $category  One of AuditAction::CATEGORIES' keys.
     * @param  CarbonImmutable|null  $from  The first day to include.
     * @param  CarbonImmutable|null  $to  The last day to include.
     */
    public function __construct(
        public ?string $projectId = null,
        public ?string $actorId = null,
        public ?string $category = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}
}
