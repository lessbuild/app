<?php

namespace App\Core\Data\Notifications;

use Carbon\CarbonImmutable;

final readonly class WorkspaceNotification
{
    public function __construct(
        public string $key,
        public string $threadKey,
        public string $workspaceId,
        public string $projectId,
        public string $projectName,
        public string $projectUrl,
        public ?string $environmentName,
        public string $product,
        public string $productLabel,
        public WorkspaceNotificationSeverity $severity,
        public string $title,
        public string $detail,
        public CarbonImmutable $occurredAt,
        public ?string $resultUrl,
        public bool $read = false,
    ) {}
}
