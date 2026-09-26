<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Models\OperationalIncident;
use App\Modules\Deployer\Models\User;

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
