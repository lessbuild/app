<?php

namespace App\Actions\Observability;

use App\Exceptions\OperationalIncidentOperationException;
use App\Models\OperationalIncident;
use App\Models\User;

class AcknowledgeOperationalIncidentAction
{
    /**
     * Acknowledge an incident and append the attributed acknowledgement event.
     *
     * @throws OperationalIncidentOperationException When the incident has already been resolved.
     */
    public function handle(OperationalIncident $incident, User $actor): void
    {
        if ($incident->status === OperationalIncident::STATUS_RESOLVED) {
            throw new OperationalIncidentOperationException('A resolved incident cannot be acknowledged.');
        }

        $incident->update([
            'status' => OperationalIncident::STATUS_ACKNOWLEDGED,
            'assigned_to' => $incident->assigned_to ?: $actor->id,
            'acknowledged_at' => $incident->acknowledged_at ?: now(),
        ]);
        $incident->events()->create(['actor_id' => $actor->id, 'type' => 'acknowledged', 'message' => 'Incident acknowledged.', 'occurred_at' => now()]);
    }
}
