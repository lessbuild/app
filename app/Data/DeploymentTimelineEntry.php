<?php

namespace App\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class DeploymentTimelineEntry
{
    public readonly ?CarbonImmutable $occurredAt;

    /**
     * Carry one honest deployment milestone for the operational status view.
     *
     * @param  'completed'|'active'|'failed'|'canceled'|'pending'  $status  Display state derived from persisted build progress.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly string $status,
        ?CarbonInterface $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt?->toImmutable();
    }
}
