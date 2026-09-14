<?php

namespace App\Data;

use App\Models\PreviewDeployment;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class PreviewLifetime
{
    public readonly CarbonImmutable $expiresAt;

    /**
     * Carry the configured lifetime projection for one active preview.
     *
     * The expiration is calculated from recorded preview activity and project
     * configuration. It is informational; the expiry command remains the only
     * lifecycle owner.
     */
    public function __construct(
        public readonly PreviewDeployment $preview,
        public readonly Project $project,
        public readonly int $ttlHours,
        CarbonInterface $expiresAt,
        public readonly bool $expired,
    ) {
        $this->expiresAt = $expiresAt->toImmutable();
    }
}
