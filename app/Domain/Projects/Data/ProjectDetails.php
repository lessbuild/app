<?php

declare(strict_types=1);

namespace App\Domain\Projects\Data;

final readonly class ProjectDetails
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}
}
