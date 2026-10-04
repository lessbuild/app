<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Models\SecurityScan;

final readonly class SecurityCheck
{
    /**
     * Create a new SecurityCheck instance.
     *
     * One of Security's checks and how its last scan went.
     *
     * @param  string  $kind  The scanner's key, such as "domains".
     * @param  string  $label  The check's name.
     * @param  bool  $included  Whether the account's plan includes it.
     * @param  string|null  $status  The last scan's status (queued, running, done or failed), or null before the first.
     * @param  string|null  $finishedAt  ISO 8601.
     * @param  string|null  $error  Why the last scan failed.
     * @param  int  $findings  The open findings the last scan left.
     */
    public function __construct(
        public string $kind,
        public string $label,
        public bool $included,
        public ?string $status,
        public ?string $finishedAt,
        public ?string $error,
        public int $findings,
    ) {}

    /**
     * Describe a check from the overview query's row.
     *
     * @param  array{kind: string, label: string, included: bool, last: SecurityScan|null}  $check
     * @return self
     */
    public static function from(array $check): self
    {
        $last = $check['last'];

        return new self(
            kind: $check['kind'],
            label: $check['label'],
            included: $check['included'],
            status: $last?->status,
            finishedAt: $last?->finished_at?->toIso8601String(),
            error: $last?->error,
            findings: $last === null ? 0 : $last->findings_count,
        );
    }
}
