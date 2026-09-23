<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class IncidentDeploymentContext
{
    public function __construct(private readonly WorkspacePlanLimits $limits) {}

    /** @return Collection<int, Deployment> */
    public function recent(Workspace $workspace, Incident $incident): Collection
    {
        $minutes = $this->limits->deploymentContextMinutes($workspace);
        if ($minutes === 0) {
            return collect();
        }

        $source = $incident->source();
        $environment = $source?->environment;
        if ($environment === null || $incident->opened_at === null) {
            return collect();
        }

        $service = $source instanceof AlertRule ? $source->service : null;

        return Deployment::forWorkspace($workspace)
            ->with(['environment.application', 'release', 'actor:id,name'])
            ->where('environment_id', $environment->id)
            ->whereBetween('deployed_at', [$incident->opened_at->subMinutes($minutes), $incident->opened_at])
            ->when($service !== null, fn (Builder $query): Builder => $query->whereHas('release', fn (Builder $release): Builder => $release->where('service', $service)))
            ->latest('deployed_at')->latest('id')->limit(10)->get();
    }
}
