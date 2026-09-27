<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Environment;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Project;

final class IncidentLocks
{
    /**
     * Lock an incident and its source in the platform's lock order: project → environment → monitor → incident.
     * Called inside a transaction, after the account lock when one is needed.
     */
    public function find(int $id): ?Incident
    {
        $incident = Incident::query()->find($id);
        $monitor = $incident?->monitor_id === null ? null : Monitor::withTrashed()->find($incident->monitor_id);
        $environment = $monitor === null ? null : Environment::query()->find($monitor->environment_id);
        if ($environment === null) {
            return null;
        }
        $project = Project::query()->lockForUpdate()->find($environment->project_id);
        $environment = Environment::query()->lockForUpdate()->find($environment->id);
        $monitor = Monitor::withTrashed()->lockForUpdate()->find($monitor->id);
        $incident = Incident::query()->lockForUpdate()->find($id);
        if ($project === null || $environment === null || $monitor === null || $incident === null) {
            return null;
        }
        $environment->setRelation('project', $project);
        $monitor->setRelation('environment', $environment);
        $incident->setRelation('monitor', $monitor);

        return $incident;
    }
}
