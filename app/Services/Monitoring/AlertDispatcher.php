<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Writes alert deliveries (the outbox) for incident events and queues them. */
final class AlertDispatcher
{
    public function __construct(private readonly AlertDeliveryQueue $queue, private readonly TelemetryRedactor $redactor) {}

    /** Called in the incident transaction, after locking its project, environment, source and incident. */
    public function record(Incident $incident, string $event): void
    {
        if (DB::transactionLevel() === 0 || ! in_array($event, ['opened', 'recovered'], true)) {
            throw new LogicException('Alert outbox writes require an incident transition transaction.');
        }
        $monitor = $incident->source();
        $environment = $monitor?->environment;
        $project = $environment?->project;
        if ($monitor === null || $environment === null || $project === null || $monitor->trashed() || ! $monitor->enabled) {
            return;
        }
        $destinations = $monitor->destinations()->where('account_id', $project->account_id)
            ->where('enabled', true)->wherePivot($event, true)->orderBy('alert_destinations.id')->lockForUpdate()->get();
        $payload = $this->redactor->redact([
            'event' => $event, 'title' => $incident->title, 'incident_id' => $incident->id,
            // "application" stays in the payload for webhook receivers built against the old Monitor app.
            'application' => $project->name, 'project' => $project->name, 'environment' => $environment->name,
            'rule' => $incident->monitor_id === null ? $incident->rule_snapshot : null,
            ...($incident->monitor_id !== null ? ['monitor' => $incident->rule_snapshot] : []),
            'observation' => $incident->latest_observation,
            'opened_at' => $incident->opened_at->toISOString(), 'resolved_at' => $incident->resolved_at?->toISOString(),
        ]);
        $payload['url'] = route('monitoring.incidents.show', [$project, $incident]);
        foreach ($destinations as $destination) {
            if ($destination->deliveries()->whereBelongsTo($incident)->where('event', $event)->exists()) {
                continue;
            }
            $this->queue($destination, $payload, $incident);
        }
        // Escalations belong to telemetry alert rules (Monitoring part 3).
    }

    /** @param array<string, mixed> $payload */
    public function queue(AlertDestination $destination, array $payload, ?Incident $incident = null): AlertDelivery
    {
        $delivery = new AlertDelivery;
        $delivery->forceFill([
            'account_id' => $destination->account_id, 'alert_destination_id' => $destination->id,
            'incident_id' => $incident?->id, 'event' => $payload['event'], 'target_revision' => $destination->target_revision,
            'payload' => $payload, 'status' => 'queued', 'generation' => 0, 'attempt_count' => 0, 'cycle_attempts' => 0,
            'next_attempt_at' => CarbonImmutable::now('UTC'),
        ])->save();
        $this->queue->dispatch($delivery);

        return $delivery;
    }
}
