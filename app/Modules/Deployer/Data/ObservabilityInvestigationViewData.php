<?php

namespace App\Modules\Deployer\Data;

final readonly class ObservabilityInvestigationViewData
{
    /**
     * Carry the normalized named-view input across the HTTP/action boundary.
     *
     * @param  string  $name  Validated display name.
     * @param  ObservabilityContextFilters  $filters  Finite environment-context filters.
     * @param  int  $expiresInDays  Validated retention choice.
     */
    public function __construct(
        public string $name,
        public ObservabilityContextFilters $filters,
        public int $expiresInDays,
    ) {}
}
