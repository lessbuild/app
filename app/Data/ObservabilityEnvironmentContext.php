<?php

namespace App\Data;

use App\Models\Build;
use App\Models\Environment;
use App\Models\OperationalIncident;
use App\Models\WebsiteHealthCheck;
use App\Models\WebsiteLogSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class ObservabilityEnvironmentContext
{
    public readonly CarbonImmutable $since;

    /**
     * Carry a secret-safe, bounded environment evidence read model.
     *
     * Build and health collections contain metadata only. Runtime-log snapshots
     * deliberately exclude their encrypted bodies and operational incidents
     * deliberately exclude encrypted summaries, resolutions and timeline bodies.
     *
     * @param  Collection<int, Build>  $builds  Recent or active environment deployments.
     * @param  Collection<int, WebsiteHealthCheck>  $healthChecks  Recent website observations.
     * @param  Collection<int, WebsiteLogSnapshot>  $runtimeLogs  Current snapshot metadata.
     * @param  Collection<int, OperationalIncident>  $incidents  Explicitly related incidents.
     */
    public function __construct(
        public readonly Environment $environment,
        public readonly string $window,
        CarbonInterface $since,
        public readonly Collection $builds,
        public readonly Collection $healthChecks,
        public readonly Collection $runtimeLogs,
        public readonly Collection $incidents,
    ) {
        $this->since = $since->toImmutable();
    }
}
