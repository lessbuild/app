<?php

namespace App\Data;

class RepositoryImpactPreview
{
    /**
     * Carry a complete read-only preview for the selected workspace repository targets.
     *
     * @param  ?list<string>  $changedPaths  Normalized changed paths, or null when unavailable.
     * @param  list<RepositoryImpactPreviewTarget>  $targets  Tenant-scoped automatic deployment targets.
     * @param  array{affected: int, unaffected: int, unknown: int}  $counts  Outcome totals for the target set.
     */
    public function __construct(
        public readonly ?array $changedPaths,
        public readonly array $targets,
        public readonly array $counts,
    ) {}

    /**
     * Determine whether the preview contains no automatic push targets.
     */
    public function isEmpty(): bool
    {
        return $this->targets === [];
    }
}
