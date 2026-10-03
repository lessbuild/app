<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\AlertRule;
use App\Models\Environment;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Project;

final class IncidentLocks
{
    /**
     * Lock an incident and its source in the platform's lock order: project → environment → monitor or rule → incident.
     * Called inside a transaction, after the account lock when one is needed.
     *
     * @param  int  $id
     * @return Incident|null
     */
    public function find(int $id): ?Incident
    {
        $incident = Incident::query()->find($id);
        $source = match (true) {
            $incident === null => null,
            $incident->monitor_id !== null => Monitor::withTrashed()->find($incident->monitor_id),
            default => AlertRule::withTrashed()->find($incident->alert_rule_id),
        };
        $environment = $source === null ? null : Environment::query()->find($source->environment_id);
        if ($source === null || $environment === null) {
            return null;
        }
        $project = Project::query()->lockForUpdate()->find($environment->project_id);
        $environment = Environment::query()->lockForUpdate()->find($environment->id);
        $source = $source::withTrashed()->lockForUpdate()->find($source->id);
        $incident = Incident::query()->lockForUpdate()->find($id);
        if ($project === null || $environment === null || $source === null || $incident === null) {
            return null;
        }
        $environment->setRelation('project', $project);
        $source->setRelation('environment', $environment);
        $incident->setRelation($source instanceof Monitor ? 'monitor' : 'alertRule', $source);

        return $incident;
    }
}
