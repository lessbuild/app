<?php

declare(strict_types=1);

namespace App\Domain\Projects\Data;

final readonly class ProjectCard
{
    /** @param list<string> $serviceNames enabled services, in registry order */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public array $serviceNames,
        public int $environmentCount,
    ) {}
}
