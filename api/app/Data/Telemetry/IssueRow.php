<?php

declare(strict_types=1);

namespace App\Data\Telemetry;

use App\Models\Issue;

final readonly class IssueRow
{
    /**
     * Create a new IssueRow instance.
     *
     * An issue as lists show it (its title and location redacted beforehand).
     *
     * @param  int  $id
     * @param  string  $title
     * @param  string|null  $location
     * @param  int  $occurrences
     * @param  string|null  $lastSeenAt  ISO 8601.
     * @param  string|null  $assignee
     * @param  string  $severity
     * @param  string  $severityLabel
     * @param  string  $status  open, resolved, snoozed or ignored.
     * @param  string  $statusLabel
     * @param  string  $statusTone
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?string $location,
        public int $occurrences,
        public ?string $lastSeenAt,
        public ?string $assignee,
        public string $severity,
        public string $severityLabel,
        public string $status,
        public string $statusLabel,
        public string $statusTone,
    ) {}

    /**
     * Describe an issue.
     *
     * @param  Issue  $issue
     * @return self
     */
    public static function from(Issue $issue): self
    {
        return new self(
            id: $issue->id,
            title: $issue->title,
            location: $issue->location,
            occurrences: (int) $issue->occurrences,
            lastSeenAt: $issue->last_seen_at->toIso8601String(),
            assignee: $issue->assignee?->name,
            severity: (string) $issue->severity,
            severityLabel: __(ucfirst((string) $issue->severity)),
            status: $issue->status->value,
            statusLabel: $issue->status->label(),
            statusTone: $issue->status->tone(),
        );
    }
}
