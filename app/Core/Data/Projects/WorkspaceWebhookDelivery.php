<?php

namespace App\Core\Data\Projects;

use Carbon\CarbonImmutable;

final readonly class WorkspaceWebhookDelivery
{
    public function __construct(
        public string $key,
        public string $product,
        public string $productLabel,
        public string $projectName,
        public string $title,
        public string $status,
        public string $statusLabel,
        public ?int $attemptCount,
        public CarbonImmutable $recordedAt,
        public ?string $resultUrl = null,
    ) {}
}
