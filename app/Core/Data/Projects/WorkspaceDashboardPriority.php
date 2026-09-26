<?php

namespace App\Core\Data\Projects;

use Carbon\CarbonInterface;

final readonly class WorkspaceDashboardPriority
{
    public function __construct(
        public string $key,
        public string $projectName,
        public string $badge,
        public string $title,
        public string $detail,
        public string $tone,
        public string $actionUrl,
        public string $actionLabel,
        public int $rank,
        public ?CarbonInterface $updatedAt = null,
    ) {}
}
