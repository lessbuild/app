<?php

namespace App\Core\Data\Projects;

use Carbon\CarbonInterface;

final readonly class ProjectProductSnapshot
{
    public function __construct(
        public string $title,
        public string $detail,
        public ProjectProductSnapshotState $state,
        public ?CarbonInterface $updatedAt = null,
        public ?string $url = null,
    ) {}
}
