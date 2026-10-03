<?php

declare(strict_types=1);

namespace App\Data\SiteAudits;

use App\Models\SiteAuditRun;

final readonly class SiteAuditRunSummary
{
    /**
     * Create a new SiteAuditRunSummary instance.
     *
     * One run of an audit, as listed.
     *
     * @param  int  $id
     * @param  string  $status  queued, running, done or failed
     * @param  string  $trigger  manual or scheduled
     * @param  int|null  $score  the site's overall score, once done
     * @param  string  $createdAt  ISO 8601
     * @param  string|null  $finishedAt  ISO 8601
     * @param  string|null  $error  why it failed
     */
    public function __construct(
        public int $id,
        public string $status,
        public string $trigger,
        public ?int $score,
        public string $createdAt,
        public ?string $finishedAt,
        public ?string $error,
    ) {}

    /**
     * Describe a run.
     *
     * @param  SiteAuditRun  $run
     * @return self
     */
    public static function from(SiteAuditRun $run): self
    {
        return new self($run->id, $run->status->value, $run->trigger, $run->score, (string) $run->created_at?->toIso8601String(), $run->finished_at?->toIso8601String(), $run->error);
    }
}
