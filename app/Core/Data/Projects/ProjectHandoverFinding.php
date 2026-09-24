<?php

namespace App\Core\Data\Projects;

final readonly class ProjectHandoverFinding
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public ?string $reference = null,
    ) {}
}
