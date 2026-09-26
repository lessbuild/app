<?php

namespace App\Core\Data\Restoration;

final readonly class ResourceRestorationTarget
{
    public function __construct(
        public string $product,
        public string $resourceType,
        public string $resourceId,
        public string $projectResourceId,
        public string $workspaceId,
        public string $projectId,
        public ?string $environmentId,
        public string $sourceWorkspaceEntity,
        public string $sourceWorkspaceId,
        public ?string $parentApplicationId = null,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
