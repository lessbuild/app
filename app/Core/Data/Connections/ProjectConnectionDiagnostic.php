<?php

namespace App\Core\Data\Connections;

use Carbon\CarbonInterface;

final readonly class ProjectConnectionDiagnostic
{
    public function __construct(
        public string $tone,
        public string $status,
        public string $summary,
        public string $detail,
        public ?string $nextStep,
        public ?CarbonInterface $lastAttemptAt,
        public ?CarbonInterface $lastSucceededAt,
        public ?CarbonInterface $lastObservedAt = null,
        public ?string $lastObservedLabel = null,
        /** Lower values are preferred when several modules report on one connection. */
        public int $priority = 50,
    ) {}
}
