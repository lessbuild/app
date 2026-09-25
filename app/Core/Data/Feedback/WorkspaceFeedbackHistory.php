<?php

namespace App\Core\Data\Feedback;

/** @param list<WorkspaceFeedbackHistoryEntry> $entries */
final readonly class WorkspaceFeedbackHistory
{
    public function __construct(
        public array $entries = [],
        public bool $available = true,
        public ?string $manageUrl = null,
    ) {}
}
