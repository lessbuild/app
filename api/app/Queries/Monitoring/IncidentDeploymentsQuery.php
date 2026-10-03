<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\Deployment;
use App\Models\Incident;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Collection;

/** Deployments to an incident's environment shortly before it opened: a lead for investigation, not proof of cause. */
final class IncidentDeploymentsQuery
{
    /**
     * Create a new IncidentDeploymentsQuery instance.
     *
     * Finds the deploys that might explain an incident.
     *
     * @param  Entitlements  $entitlements  Decides how far back the account's plan looks.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Get how far back the account's Monitoring tier looks; 0 means the feature isn't included.
     *
     * @param  Incident  $incident
     * @return int
     */
    public function minutes(Incident $incident): int
    {
        return (int) ($this->entitlements->for($incident->account)->limit('monitoring.deployment_context.minutes') ?? 0);
    }

    /**
     * Find up to ten deploys to the incident's environment in the window before it opened. Empty when the plan doesn't
     * include deployment context or the incident has no environment.
     *
     * @param  Incident  $incident
     * @return Collection<int, Deployment>
     */
    public function handle(Incident $incident): Collection
    {
        $minutes = $this->minutes($incident);
        $environmentId = $incident->monitor?->environment_id;
        if ($minutes === 0 || $environmentId === null) {
            return collect();
        }

        return Deployment::query()->with(['release', 'actor'])
            ->where('environment_id', $environmentId)
            ->whereBetween('deployed_at', [$incident->opened_at->subMinutes($minutes), $incident->opened_at])
            ->latest('deployed_at')->latest('id')->limit(10)->get();
    }
}
