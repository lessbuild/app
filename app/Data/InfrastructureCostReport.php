<?php

namespace App\Data;

use Illuminate\Support\Collection;

class InfrastructureCostReport
{
    /**
     * Carry the bounded workspace cost projection used by the cost page.
     *
     * @param  Collection<int, InfrastructureCostRow>  $rows  Organization-scoped server rows.
     */
    public function __construct(
        public readonly Collection $rows,
        public readonly float $estimated,
        public readonly int $unknownCount,
        public readonly int $idleCount,
    ) {}
}
