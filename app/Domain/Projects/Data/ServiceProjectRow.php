<?php

declare(strict_types=1);

namespace App\Domain\Projects\Data;

final readonly class ServiceProjectRow
{
    public function __construct(
        public string $projectId,
        public string $projectName,
        public bool $enabled,
        public bool $canManage,
    ) {}
}
