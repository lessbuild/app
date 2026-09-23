<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\MonitorObservation;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Monitor;
use Carbon\CarbonImmutable;

final class RecordMonitorResult
{
    public function __construct(
        private readonly ChangeIncident $incidents,
        private readonly RecordAlertDeliveries $deliveries,
        private readonly MaintenanceWindowState $maintenance,
    ) {}

    /** Caller holds the source and monitor locks inside the observation transaction. */
    public function record(Monitor $monitor, MonitorObservation $result, CarbonImmutable $now, string $location, bool $resetStreaks = false): void
    {
        $failures = $result->outcome === 'down' ? ($resetStreaks ? 0 : $monitor->failure_streak) + 1 : 0;
        $recoveries = $result->outcome === 'up' ? ($resetStreaks ? 0 : $monitor->recovery_streak) + 1 : 0;
        $observation = [...$result->toArray(), 'location' => $location, 'checked_at' => $now->toISOString()];
        $monitor->forceFill([
            'health' => $result->outcome, 'checked_at' => $now,
            'failure_streak' => min(10, $failures), 'recovery_streak' => min(10, $recoveries), 'observation' => $observation,
        ])->save();
        $maintenanceActive = $this->maintenance->isActiveForWorkspaceId($monitor->environment->application->workspace_id, $now);
        $incident = $monitor->incidents()->where('active_slot', true)->lockForUpdate()->first();
        if ($incident !== null) {
            $incident->setRelation('monitor', $monitor);
            $incident->forceFill(['latest_observation' => $observation]);
            if ($result->outcome === 'down') {
                $incident->forceFill(['last_breached_at' => $now]);
            }
            $incident->save();
            if ($recoveries >= $monitor->recovery_checks) {
                $this->incidents->close($incident, 'recovered', $now);
            }
        } elseif ($failures >= $monitor->trigger_checks && ! $maintenanceActive) {
            $incident = new Incident;
            $incident->forceFill([
                'monitor_id' => $monitor->id, 'alert_rule_id' => null, 'active_slot' => true,
                'title' => mb_substr($monitor->name.' — '.($monitor->type === 'http' ? 'uptime' : $monitor->typeLabel()).' check failed', 0, 255),
                'status' => 'open', 'state_version' => 0, 'rule_snapshot' => $monitor->snapshot(),
                'opening_observation' => $observation, 'latest_observation' => $observation,
                'opened_at' => $now, 'last_breached_at' => $now,
            ])->save();
            $incident->setRelation('monitor', $monitor);
            $incident->activities()->create(['action' => 'monitor_failed', 'metadata' => ['observation' => $observation]]);
            $this->deliveries->record($incident, 'opened');
        }
    }
}
