<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Incident;

final class LockIncident
{
    /** Called inside a transaction, after the workspace lock when one is needed. */
    public function find(int $id): ?Incident
    {
        $incident = Incident::query()->find($id);
        $source = $incident?->source();
        $environment = $source === null ? null : Environment::withTrashed()->find($source->environment_id);
        if ($environment === null) {
            return null;
        }
        $application = Application::withTrashed()->lockForUpdate()->find($environment->application_id);
        $environment = Environment::withTrashed()->lockForUpdate()->find($environment->id);
        $source = $source::withTrashed()->lockForUpdate()->find($source->id);
        $incident = Incident::query()->lockForUpdate()->find($id);
        $environment?->setRelation('application', $application);
        $source?->setRelation('environment', $environment);
        $incident?->setRelation($incident->monitor_id === null ? 'alertRule' : 'monitor', $source);

        return $incident;
    }
}
