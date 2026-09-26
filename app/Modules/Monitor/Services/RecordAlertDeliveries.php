<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class RecordAlertDeliveries
{
    public function __construct(private readonly AlertDeliveryQueue $queue, private readonly TelemetryRedactor $redactor) {}

    /** Called in the incident transaction, after locking its application, environment, rule and incident. */
    public function record(Incident $incident, string $event): void
    {
        if (DB::connection('monitor')->transactionLevel() === 0 || ! in_array($event, ['opened', 'recovered'], true)) {
            throw new LogicException('Alert outbox writes require an incident transition transaction.');
        }
        $rule = $incident->source();
        $environment = $rule?->environment;
        $application = $environment?->application;
        if ($application === null || $rule->trashed() || ! $rule->enabled) {
            return;
        }
        $destinations = $rule->destinations()->where('workspace_id', $application->workspace_id)
            ->where('enabled', true)->wherePivot($event, true)->orderBy('alert_destinations.id')->lockForUpdate()->get();
        $payload = $this->redactor->redact([
            'event' => $event, 'title' => $incident->title, 'incident_id' => $incident->id,
            'application' => $application->name, 'environment' => $environment->name,
            'rule' => $incident->monitor_id === null ? $incident->rule_snapshot : null,
            ...($incident->monitor_id !== null ? ['monitor' => $incident->rule_snapshot] : []),
            'observation' => $incident->latest_observation,
            'opened_at' => $incident->opened_at->toISOString(), 'resolved_at' => $incident->resolved_at?->toISOString(),
        ]);
        $payload['url'] = route('monitor.incidents.show', $incident);
        foreach ($destinations as $destination) {
            if ($destination->deliveries()->whereBelongsTo($incident)->where('event', $event)->exists()) {
                continue;
            }
            $this->create($destination, $payload, $incident);
        }
        if ($event !== 'opened' || ! $rule instanceof AlertRule) {
            return;
        }
        $immediateDestinationIds = $destinations->modelKeys();
        $escalations = $rule->escalations()->with('destination')->where('enabled', true)->get();
        foreach ($escalations as $escalation) {
            $destination = $escalation->destination;
            if ($destination === null || in_array($destination->id, $immediateDestinationIds, true)
                || $destination->workspace_id !== $application->workspace_id || ! $destination->enabled
                || $destination->deliveries()->whereBelongsTo($incident)->where('event', 'escalated')->exists()) {
                continue;
            }
            $this->create($destination, [
                ...$payload,
                'event' => 'escalated',
                'escalation' => ['step' => $escalation->position + 1, 'delay_minutes' => $escalation->delay_minutes],
            ], $incident, CarbonImmutable::now('UTC')->addMinutes($escalation->delay_minutes));
        }
    }

    public function test(Workspace $workspace, User $actor, AlertDestination $destination, int $version): AlertDelivery
    {
        return DB::connection('monitor')->transaction(function () use ($workspace, $actor, $destination, $version): AlertDelivery {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);
            $destination = AlertDestination::forWorkspace($workspace)->lockForUpdate()->findOrFail($destination->id);
            abort_unless($destination->state_version === $version, 409, 'This destination changed. Refresh before trying again.');
            abort_unless($destination->enabled, 409, 'Enable this destination before sending a test.');

            return $this->create($destination, [
                'event' => 'test', 'title' => 'Test notification', 'incident_id' => null,
                'application' => config('app.name').' test', 'environment' => 'Test only', 'url' => null,
                'rule' => null, 'observation' => null, 'opened_at' => null, 'resolved_at' => null,
            ]);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $payload */
    private function create(AlertDestination $destination, array $payload, ?Incident $incident = null, ?CarbonImmutable $nextAttemptAt = null): AlertDelivery
    {
        $delivery = new AlertDelivery;
        $delivery->forceFill([
            'workspace_id' => $destination->workspace_id, 'alert_destination_id' => $destination->id,
            'incident_id' => $incident?->id, 'event' => $payload['event'], 'target_revision' => $destination->target_revision,
            'payload' => $payload, 'status' => 'queued', 'generation' => 0, 'attempt_count' => 0, 'cycle_attempts' => 0,
            'next_attempt_at' => $nextAttemptAt ?? CarbonImmutable::now('UTC'),
        ])->save();
        $this->queue->dispatch($delivery);

        return $delivery;
    }
}
