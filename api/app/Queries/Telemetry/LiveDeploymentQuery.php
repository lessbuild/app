<?php

declare(strict_types=1);

namespace App\Queries\Telemetry;

use App\Models\Deployment;
use Carbon\CarbonInterface;

/** The deploy that was live in an environment at a moment: what served a request or trace. */
final class LiveDeploymentQuery
{
    /**
     * Find the latest deployment to the environment at or before the moment. Null when nothing was deployed yet.
     *
     * @param  string  $environmentId
     * @param  CarbonInterface  $at
     * @return Deployment|null
     */
    public function handle(string $environmentId, CarbonInterface $at): ?Deployment
    {
        return Deployment::query()->with(['release', 'environment'])->where('environment_id', $environmentId)
            ->where('deployed_at', '<=', $at)->orderByDesc('deployed_at')->orderByDesc('id')->first();
    }
}
