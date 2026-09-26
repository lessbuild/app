<?php

namespace App\Core\Data\Deletion;

final readonly class ProductDeletionTarget
{
    /** @param list<string> $sourceWorkspaceIds Native owned workspaces included in an account request. */
    public function __construct(
        public string $product,
        public string $kind,
        public string $sourceId,
        public string $actorSourceId,
        public string $canonicalId,
        public string $actorId,
        public array $sourceWorkspaceIds = [],
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
