<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ChangeIncident
{
    public function __construct(private readonly TelemetryRedactor $redactor, private readonly RecordAlertDeliveries $deliveries, private readonly LockIncident $lock) {}

    /** @param array{action: string, version: int|string, assignee_id?: int|string|null, note?: string|null} $data */
    public function update(Incident $incident, Workspace $workspace, User $actor, array $data): Incident
    {
        return DB::connection('monitor')->transaction(function () use ($incident, $workspace, $actor, $data): Incident {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            $incident = $this->lock->find($incident->id);
            abort_unless($incident !== null && $incident->source()?->environment?->application?->workspace_id === $workspace->id, 404);
            Gate::forUser($actor)->authorize('update', $incident);
            Gate::forUser($actor)->authorize('contribute', $workspace);
            abort_unless($incident->state_version === (int) $data['version'], 409, 'This incident changed. Refresh before trying again.');

            if ($data['action'] === 'assign') {
                $assigneeId = isset($data['assignee_id']) ? (int) $data['assignee_id'] : null;

                if ($assigneeId !== null && ! $workspace->members()->whereKey($assigneeId)->wherePivotIn('role', ['owner', 'admin', 'member'])->exists()) {
                    throw ValidationException::withMessages(['assignee_id' => 'Choose a current workspace contributor.']);
                }

                if ($incident->assignee_id === $assigneeId) {
                    return $incident;
                }

                $incident->forceFill(['assignee_id' => $assigneeId]);
            } elseif ($data['action'] === 'acknowledge') {
                abort_if($incident->status === 'resolved', 409, 'This incident is already closed.');
                if ($incident->status === 'acknowledged') {
                    return $incident;
                }
                $incident->forceFill(['status' => 'acknowledged', 'acknowledged_at' => CarbonImmutable::now('UTC'), 'acknowledged_by' => $actor->id]);
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

    /** The caller holds the workspace lock while changing membership. */
    public function unassignMember(Workspace $workspace, User $member, User $actor): void
    {
        Incident::query()->where(function (Builder $query) use ($workspace): void {
            $environmentIds = Environment::withTrashed()
                ->whereIn('application_id', $workspace->applications()->withTrashed()->select('id'))
                ->select('id');

            $query->whereIn('alert_rule_id', AlertRule::withTrashed()->whereIn('environment_id', $environmentIds)->select('id'))
                ->orWhereIn('monitor_id', Monitor::withTrashed()->whereIn('environment_id', $environmentIds)->select('id'));
        })->where('assignee_id', $member->id)->orderBy('id')->lockForUpdate()
            ->eachById(function (Incident $incident) use ($actor, $member): void {
                $incident->forceFill(['assignee_id' => null, 'state_version' => $incident->state_version + 1])->save();
                $incident->activities()->create([
                    'actor_id' => $actor->id, 'action' => 'assignee_unavailable',
                    'metadata' => ['before' => ['assignee_id' => $member->id], 'assignee_id' => null],
                ]);
            }, 100);
    }

    /** Called inside a transaction holding the rule and incident locks. */
    public function close(Incident $incident, string $reason, CarbonImmutable $now, ?User $actor = null): void
    {
        $incident->forceFill([
            'status' => 'resolved', 'active_slot' => null, 'resolved_at' => $now,
            'closure_reason' => $reason, 'state_version' => $incident->state_version + 1,
        ])->save();
        $incident->activities()->create([
            'actor_id' => $actor?->id, 'action' => $reason,
            'metadata' => ['observation' => $incident->latest_observation],
        ]);
        if ($reason === 'recovered') {
            $this->deliveries->record($incident, 'recovered');
        }
    }
}
