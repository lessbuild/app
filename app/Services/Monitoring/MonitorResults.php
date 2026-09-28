<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\Monitoring\MonitorObservation;
use App\Models\Incident;
use App\Models\Monitor;
use Carbon\CarbonImmutable;

final class MonitorResults
{
    /**
     * Create a new MonitorResults instance.
     *
     * Turns monitor observations into health, incidents and alerts.
     *
     * @param  IncidentLifecycle  $incidents  Closes incidents on recovery.
     * @param  AlertDispatcher  $deliveries  Queues alerts for opened and recovered incidents.
     * @param  MaintenanceWindowState  $maintenance  Holds back incidents during maintenance windows.
     */
    public function __construct(
        private readonly IncidentLifecycle $incidents,
        private readonly AlertDispatcher $deliveries,
        private readonly MaintenanceWindowState $maintenance,
    ) {}

    /**
     * Record a monitor result: update its health and streaks, then open, update or close its incident (holding back
     * new incidents during maintenance).
     *
     * Caller holds the source and monitor locks inside the observation transaction.
     *
     * @param  Monitor  $monitor
     * @param  MonitorObservation  $result
     * @param  CarbonImmutable  $now
     * @param  string  $location
     * @param  bool  $resetStreaks
     * @return void
     */
    public function record(Monitor $monitor, MonitorObservation $result, CarbonImmutable $now, string $location, bool $resetStreaks = false): void
    {
        $failures = $result->outcome === 'down' ? ($resetStreaks ? 0 : $monitor->failure_streak) + 1 : 0;
        $recoveries = $result->outcome === 'up' ? ($resetStreaks ? 0 : $monitor->recovery_streak) + 1 : 0;
        $observation = [...$result->toArray(), 'location' => $location, 'checked_at' => $now->toISOString()];
        $monitor->forceFill([
            'health' => $result->outcome, 'checked_at' => $now,
            'failure_streak' => min(10, $failures), 'recovery_streak' => min(10, $recoveries), 'observation' => $observation,
        ])->save();
        $maintenanceActive = $this->maintenance->isActiveForAccountId($monitor->environment->project->account_id, $now);
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
                'account_id' => $monitor->environment->project->account_id, 'project_id' => $monitor->environment->project_id,
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
