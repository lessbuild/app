<?php

namespace App\Data;

use Illuminate\Support\Collection;

class PreviewUsageSummary
{
    /**
     * Carry the bounded read-only preview quota and lifetime projection.
     *
     * @param  Collection<int, PreviewLifetime>  $previews  Organization-scoped active previews.
     */
    public function __construct(
        public readonly int $used,
        public readonly ?int $limit,
        public readonly bool $allowed,
        public readonly Collection $previews,
        public readonly int $hiddenCount,
    ) {}
}
