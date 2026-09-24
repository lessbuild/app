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
    ) {}
}
