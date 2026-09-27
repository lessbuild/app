<?php

declare(strict_types=1);

namespace App\Data\Projects;

final readonly class ChecklistStep
{
    public function __construct(
        public string $label,
        public string $description,
        public bool $done,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
    ) {}
}
