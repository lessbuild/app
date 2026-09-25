<?php

namespace App\Core\Data\Blueprints;

final readonly class BlueprintTarget
{
    /** @param array<string, array{id: ?string, name: string, type: string}> $environments */
    public function __construct(
        public string $actorId,
        public string $workspaceId,
        public string $projectId,
        public array $environments,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
