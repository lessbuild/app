<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\AlertRule;
use App\Models\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Writes alert deliveries (the outbox) for incident events and queues them. */
final class AlertDispatcher
{
    /**
     * Create a new AlertDispatcher instance.
     *
     * Writes alert deliveries and queues them.
     *
     * @param  AlertDeliveryQueue  $queue  Queues each delivery's job.
     * @param  TelemetryRedactor  $redactor  Redacts titles before they leave the platform.
     */
    public function __construct(private readonly AlertDeliveryQueue $queue, private readonly TelemetryRedactor $redactor) {}

    /**
     * Queue an alert to every enabled destination routed for the incident's event (opened or recovered).
     *
     * Called in the incident transaction, after locking its project, environment, source and incident.
     *
     * @param  Incident  $incident
     * @param  string  $event
     * @return void
     */
    public function record(Incident $incident, string $event): void
    {
        if (DB::transactionLevel() === 0 || ! in_array($event, ['opened', 'recovered'], true)) {
            throw new LogicException('Alert outbox writes require an incident transition transaction.');
        }
        $source = $incident->source();
        $environment = $source?->environment;
        $project = $environment?->project;
        if ($source === null || $environment === null || $project === null || $source->trashed() || ! $source->enabled) {
            return;
        }
        $destinations = $source->destinations()->where('account_id', $project->account_id)
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
        if ($event !== 'opened' || ! $source instanceof AlertRule) {
            return;
        }
        // Escalation steps are queued now for later; the runner cancels them if the incident recovers first.
        $immediate = $destinations->modelKeys();
        foreach ($source->escalations()->with('destination')->where('enabled', true)->get() as $escalation) {
            $destination = $escalation->destination;
            if (in_array($destination->id, $immediate, true) || $destination->account_id !== $project->account_id || ! $destination->enabled
                || $destination->deliveries()->whereBelongsTo($incident)->where('event', 'escalated')->exists()) {
                continue;
            }
            $this->queue($destination, [
                ...$payload, 'event' => 'escalated',
                'escalation' => ['step' => $escalation->position + 1, 'delay_minutes' => $escalation->delay_minutes],
            ], $incident, CarbonImmutable::now('UTC')->addMinutes($escalation->delay_minutes));
        }
    }

    /**
     * Write a delivery for the destination and queues it, now or at `$sendAt`.
     *
     * @param  AlertDestination  $destination
     * @param  array<string, mixed>  $payload
     * @param  Incident|null  $incident
     * @param  CarbonImmutable|null  $sendAt
     * @return AlertDelivery
     */
    public function queue(AlertDestination $destination, array $payload, ?Incident $incident = null, ?CarbonImmutable $sendAt = null): AlertDelivery
    {
        $delivery = new AlertDelivery;
        $delivery->forceFill([
            'account_id' => $destination->account_id, 'alert_destination_id' => $destination->id,
            'incident_id' => $incident?->id, 'event' => $payload['event'], 'target_revision' => $destination->target_revision,
            'payload' => $payload, 'status' => 'queued', 'generation' => 0, 'attempt_count' => 0, 'cycle_attempts' => 0,
            'next_attempt_at' => $sendAt ?? CarbonImmutable::now('UTC'),
        ])->save();
        $this->queue->dispatch($delivery);

        return $delivery;
    }
}
