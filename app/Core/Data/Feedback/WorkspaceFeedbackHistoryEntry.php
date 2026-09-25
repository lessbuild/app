<?php

namespace App\Core\Data\Feedback;

use Carbon\CarbonImmutable;

/** A read-only projection of a feedback record that remains owned by a product database. */
final readonly class WorkspaceFeedbackHistoryEntry
{
    public function __construct(
        public string $sourceId,
        public string $product,
        public string $category,
        public string $severity,
        public string $status,
        public string $title,
        public string $description,
        public ?string $reproductionSteps,
        public ?string $reviewResponse,
        public ?string $page,
        public ?string $submitterName,
        public CarbonImmutable $createdAt,
    ) {}
}
