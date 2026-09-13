<?php

namespace App\Data;

use App\Models\Website;

final readonly class DeploymentObservationClaim
{
    /**
     * Carry a leased observation and the immutable target prepared for its remote probe.
     *
     * @param  int  $observationId  Observation row held by the claim.
     * @param  string  $claimToken  Lease token required for every later write.
     * @param  Website  $target  Unsaved website-shaped target carrying the captured URL/path and current server credentials.
     */
    public function __construct(
        public int $observationId,
        public string $claimToken,
        public Website $target,
    ) {}
}
