<?php

namespace App\Actions\Observability;

use App\Models\OperationalIncident;
use App\Models\User;

class AddOperationalIncidentNoteAction
{
    /**
     * Append a validated, actor-attributed note to an operational incident.
     */
    public function handle(OperationalIncident $incident, User $actor, string $message): void
    {
        $incident->events()->create(['actor_id' => $actor->id, 'type' => 'note', 'message' => $message, 'occurred_at' => now()]);
    }
}
