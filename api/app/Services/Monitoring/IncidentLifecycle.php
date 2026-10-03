<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Account;
use App\Models\Incident;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;

final class IncidentLifecycle
{
    /**
     * Create a new IncidentLifecycle instance.
     *
     * Opens, closes and changes incidents.
     *
     * @param  AlertDispatcher  $alerts  Queues alerts for incident events.
     */
    public function __construct(private readonly AlertDispatcher $alerts) {}

    /**
     * Resolve an incident with a reason, record it on the timeline, and send recovery alerts when it recovered.
     *
     * Called inside a transaction holding the source and incident locks.
     *
     * @param  Incident  $incident
     * @param  string  $reason
     * @param  CarbonImmutable  $now
     * @param  User|null  $actor
     * @return void
     */
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
            $this->alerts->record($incident, 'recovered');
        }
    }

    /**
     * Unassign someone who left the account or lost access to Monitoring from its incidents and issues.
     *
     * @param  Account  $account
     * @param  User  $member
     * @param  User|null  $actor
     * @return void
     */
    public function unassignMember(Account $account, User $member, ?User $actor): void
    {
        Issue::query()->whereIn('project_id', Project::query()->where('account_id', $account->id)->select('id'))
            ->where('assignee_id', $member->id)->orderBy('id')->lockForUpdate()
            ->eachById(function (Issue $issue) use ($member, $actor): void {
                $issue->forceFill(['assignee_id' => null, 'state_version' => $issue->state_version + 1])->save();
                $issue->activities()->create([
                    'actor_id' => $actor?->id, 'action' => 'assignee_unavailable',
                    'metadata' => ['before' => ['assignee_id' => $member->id], 'assignee_id' => null],
                ]);
            }, 100);

        Incident::query()->where('account_id', $account->id)->where('assignee_id', $member->id)
            ->orderBy('id')->lockForUpdate()
            ->eachById(function (Incident $incident) use ($actor, $member): void {
                $incident->forceFill(['assignee_id' => null, 'state_version' => $incident->state_version + 1])->save();
                $incident->activities()->create([
                    'actor_id' => $actor?->id, 'action' => 'assignee_unavailable',
                    'metadata' => ['before' => ['assignee_id' => $member->id], 'assignee_id' => null],
                ]);
            }, 100);
    }
}
