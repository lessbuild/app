<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Exceptions\StateConflict;
use App\Models\Incident;
use App\Models\Membership;
use App\Models\User;
use App\Services\Monitoring\IncidentLocks;
use App\Services\Monitoring\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateIncident
{
    /**
     * Create a new UpdateIncident instance.
     *
     * Acknowledges, assigns, annotates or resolves an incident.
     *
     * @param  TelemetryRedactor  $redactor  Redacts notes before they're stored.
     * @param  IncidentLocks  $locks  Locks the incident with its project.
     */
    public function __construct(private readonly TelemetryRedactor $redactor, private readonly IncidentLocks $locks) {}

    /**
     * Acknowledge an incident, change who is working on it, or add a note to its timeline.
     *
     * @param  Incident  $incident
     * @param  User  $actor
     * @param  array{action: string, version: int|string, assignee_id?: string|null, note?: string|null}  $data
     * @return Incident
     */
    public function handle(Incident $incident, User $actor, array $data): Incident
    {
        return DB::transaction(function () use ($incident, $actor, $data): Incident {
            $locked = $this->locks->find($incident->id);
            abort_unless($locked !== null && $locked->project !== null, 404);
            $incident = $locked;
            Gate::forUser($actor)->authorize('update', $incident);
            StateConflict::unlessVersion($incident->state_version, (int) $data['version'], __('This incident changed. Refresh before trying again.'));

            if ($data['action'] === 'assign') {
                $assigneeId = ($data['assignee_id'] ?? '') === '' ? null : (string) $data['assignee_id'];
                if ($assigneeId !== null && ! (Membership::query()->where('account_id', $incident->account_id)->where('user_id', $assigneeId)->first()?->canTakeMonitoringAssignments() ?? false)) {
                    throw ValidationException::withMessages(['assignee_id' => __('Choose a member who works on Monitoring in this account.')]);
                }
                if ($incident->assignee_id === $assigneeId) {
                    return $incident;
                }
                $incident->forceFill(['assignee_id' => $assigneeId]);
            } elseif ($data['action'] === 'acknowledge') {
                StateConflict::unless(! ($incident->status === 'resolved'), __('This incident is already closed.'));
                if ($incident->status === 'acknowledged') {
                    return $incident;
                }
                $incident->forceFill(['status' => 'acknowledged', 'acknowledged_at' => CarbonImmutable::now('UTC'), 'acknowledged_by' => $actor->id]);
            } elseif ($data['action'] !== 'note') {
                throw ValidationException::withMessages(['action' => __('Choose acknowledge, assign or note.')]);
            }

            $incident->forceFill(['state_version' => $incident->state_version + 1])->save();
            $incident->activities()->create([
                'actor_id' => $actor->id, 'action' => $data['action'],
                'metadata' => $data['action'] === 'assign' ? ['assignee_id' => $incident->assignee_id] : null,
                'note' => $this->redactor->redact(['note' => $data['note'] ?? null])['note'],
            ]);

            return $incident;
        }, attempts: 3);
    }
}
