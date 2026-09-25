<?php

namespace App\Core\Data\Analytics;

final readonly class AnalyticsSiteDeletionOutcome
{
    public function __construct(
        public string $requestId,
        public string $status,
        public ?string $reasonCode = null,
    ) {}

    public function completed(): bool
    {
        return $this->status === 'completed';
    }
}
