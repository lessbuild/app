<?php

namespace App\Actions\Observability;

use App\Exceptions\OperationalIncidentOperationException;
use App\Models\OperationalIncident;
use App\Models\User;

class AssignOperationalIncidentAction
{
    /**
     * Validate workspace responder membership, update ownership, and append the assignment event.
     *
     * @throws OperationalIncidentOperationException When the selected responder is outside the workspace.
     */
    public function handle(OperationalIncident $incident, User $actor, ?int $assignedTo): void
    {
        if (filled($assignedTo)) {
            $organization = $incident->organization;
            $isResponder = (int) $organization->owner_id === $assignedTo
                || $organization->members()->whereKey($assignedTo)->exists();
            if (! $isResponder) {
                throw new OperationalIncidentOperationException('The selected responder is not a member of this workspace.');
            }
        }

        $incident->update(['assigned_to' => $assignedTo]);
        $name = $incident->fresh()->assignee?->name ?? 'Unassigned';
        $incident->events()->create(['actor_id' => $actor->id, 'type' => 'assigned', 'message' => 'Owner changed to '.$name.'.', 'occurred_at' => now()]);
    }
}
