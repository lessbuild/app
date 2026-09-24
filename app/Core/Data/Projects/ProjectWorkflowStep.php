<?php

namespace App\Core\Data\Projects;

use App\Core\Enums\ProjectWorkflowStepState;
use Carbon\CarbonImmutable;

final readonly class ProjectWorkflowStep
{
    public function __construct(
        public string $product,
        public string $productLabel,
        public string $title,
        public string $detail,
        public ProjectWorkflowStepState $state,
        public ?CarbonImmutable $recordedAt = null,
        public ?CarbonImmutable $attemptedAt = null,
        public ?CarbonImmutable $completedAt = null,
        public ?string $connectionId = null,
        public ?string $deliveryId = null,
        public ?string $retryUrl = null,
        public ?string $resultUrl = null,
    ) {}
}
