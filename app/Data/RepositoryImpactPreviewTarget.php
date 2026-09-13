<?php

namespace App\Data;

use App\Models\Repository;

class RepositoryImpactPreviewTarget
{
    /**
     * Carry one tenant-scoped automatic deployment target and its read-only path decision.
     *
     * @param  RepositoryChangeImpact  $impact  Conservative result from the shared pure evaluator.
     */
    public function __construct(
        public readonly Repository $repository,
        public readonly RepositoryChangeImpact $impact,
    ) {}
}
