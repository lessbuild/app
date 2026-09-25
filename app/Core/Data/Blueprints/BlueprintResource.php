<?php

namespace App\Core\Data\Blueprints;

final readonly class BlueprintResource
{
    public function __construct(
        public string $type,
        public string $sourceId,
        public string $name,
        public ?string $environmentKey = null,
        public ?string $parentSourceId = null,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
