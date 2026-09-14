<?php

namespace App\Data;

use App\Models\Server;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class InfrastructureCostRow
{
    public readonly ?CarbonImmutable $catalogObservedAt;

    /**
     * Carry one server's explicit estimate and measured utilization signal.
     *
     * A catalog observation is deliberately separate from CPU telemetry. The
     * row does not represent provider billing or an environment allocation.
     */
    public function __construct(
        public readonly Server $server,
        public readonly ?float $monthly,
        public readonly ?float $averageCpu,
        public readonly bool $idle,
        ?CarbonInterface $catalogObservedAt,
    ) {
        $this->catalogObservedAt = $catalogObservedAt?->toImmutable();
    }
}
